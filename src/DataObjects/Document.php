<?php
namespace Dkd\PhpCmis\DataObjects;

/**
 * This file is part of php-cmis-lib.
 *
 * (c) Sascha Egerer <sascha.egerer@dkd.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Dkd\PhpCmis\Constants;
use Dkd\PhpCmis\Data\AceInterface;
use Dkd\PhpCmis\Data\ContentStreamHashInterface;
use Dkd\PhpCmis\Data\DocumentInterface;
use Dkd\PhpCmis\Data\ObjectIdInterface;
use Dkd\PhpCmis\Enum\CmisVersion;
use Dkd\PhpCmis\Enum\IncludeRelationships;
use Dkd\PhpCmis\Enum\Updatability;
use Dkd\PhpCmis\Enum\VersioningState;
use Dkd\PhpCmis\Exception\CmisNotSupportedException;
use Dkd\PhpCmis\Exception\CmisRuntimeException;
use Dkd\PhpCmis\OperationContextInterface;
use Dkd\PhpCmis\Data\PolicyInterface;
use Dkd\PhpCmis\PropertyIds;
use GuzzleHttp\Stream\StreamInterface;

/**
 * Cmis document implementation
 */
class Document extends AbstractFileableCmisObject implements DocumentInterface
{
    /**
     * Appends a content stream to the content stream of the document and refreshes this object afterwards.
     * If the repository created a new version, this new document is returned.
     * Otherwise the current document is returned.
     * The stream in contentStream is consumed but not closed by this method.
     *
     * @param StreamInterface $contentStream the content stream
     * @param boolean $isLastChunk indicates if this stream is the last chunk of the content
     * @param boolean $refresh if this parameter is set to true, this object will be refreshed after the
     * content stream has been appended
     * @return ObjectIdInterface|null the updated object ID, or null if the repository did not return an object ID
     * @throws CmisNotSupportedException
     */
    public function appendContentStream(StreamInterface $contentStream, $isLastChunk, $refresh = true)
    {
        if ($this->getSession()->getRepositoryInfo()->getCmisVersion()->equals(CmisVersion::CMIS_1_0)) {
            throw new CmisNotSupportedException('This method is not supported for CMIS 1.0 repositories.');
        }

        $newObjectId = $this->getId();
        $changeToken = $this->getPropertyValue(PropertyIds::CHANGE_TOKEN);

        $this->getBinding()->getObjectService()->appendContentStream(
            $this->getRepositoryId(),
            $newObjectId,
            $changeToken,
            $this->getObjectFactory()->convertContentStream($contentStream),
            $isLastChunk
        );

        if ($refresh) {
            $this->refresh();
        }

        if ($newObjectId === null) {
            return null;
        }

        return $this->getSession()->createObjectId($newObjectId);
    }

    /**
     * If this is a PWC (private working copy) the check out will be reversed.
     */
    public function cancelCheckOut()
    {
        $this->getBinding()->getVersioningService()->cancelCheckOut($this->getRepositoryId(), $this->getId());

        $this->getSession()->removeObjectFromCache($this);
    }

    /**
     * If this is a PWC (private working copy) it performs a check in.
     * If this is not a PWC an exception will be thrown.
     * The stream in contentStream is consumed but not closed by this method.
     *
     * @param boolean $major <code>true</code> if the checked-in document object MUST be a major version.
     *     <code>false</code> if the checked-in document object MUST NOT be a major version but a minor version.
     * @param array $properties The property values that MUST be applied to the checked-in document object.
     * @param StreamInterface $contentStream The content stream that MUST be stored for the checked-in document object.
     *     The method of passing the contentStream to the server and the encoding mechanism will be specified by each
     *     specific binding. MUST be required if the type requires it.
     * @param string $checkinComment Textual comment associated with the given version. MAY be "not set".
     * @param PolicyInterface[] $policies A list of policy ids that MUST be applied to the newly-created document
     *     object
     * @param AceInterface[] $addAces A list of ACEs that MUST be added to the newly-created document object.
     * @param AceInterface[] $removeAces A list of ACEs that MUST be removed from the newly-created document object.
     * @return ObjectIdInterface|null The id of the checked-in document. <code>null</code> if repository has not
     *     returned the new object id which could happen in case of a repository error
     */
    public function checkIn(
        $major,
        array $properties,
        StreamInterface $contentStream,
        $checkinComment,
        array $policies = array(),
        array $addAces = array(),
        array $removeAces = array()
    ) {
        $newObjectId = $this->getId();

        $objectFactory = $this->getObjectFactory();
        $updatability = array(
            Updatability::cast(Updatability::READWRITE),
            Updatability::cast(Updatability::WHENCHECKEDOUT)
        );

        $this->getBinding()->getVersioningService()->checkIn(
            $this->getRepositoryId(),
            $newObjectId,
            $major,
            $objectFactory->convertProperties(
                $properties,
                $this->getObjectType(),
                $this->getSecondaryTypes(),
                $updatability
            ),
            $objectFactory->convertContentStream($contentStream),
            $checkinComment,
            $objectFactory->convertPolicies($policies),
            $objectFactory->convertAces($addAces),
            $objectFactory->convertAces($removeAces)
        );

        // remove PWC from cache, it doesn't exist anymore
        $this->getSession()->removeObjectFromCache($this);

        if ($newObjectId === null) {
            return null;
        }

        $this->getSession()->createObjectId($newObjectId);
    }

    /**
     * Checks out the document and returns the object ID of the PWC (private working copy).
     *
     * @return ObjectIdInterface|null PWC object ID
     */
    public function checkOut()
    {
        $newObjectId = $this->getId();

        $this->getBinding()->getVersioningService()->checkOut($this->getRepositoryId(), $newObjectId);

        if ($newObjectId === null) {
            return null;
        }

        return $this->getSession()->createObjectId($newObjectId);
    }

    /**
     * Creates a copy of this document, including content.
     *
     * @param ObjectIdInterface $targetFolderId the ID of the target folder, null to create an unfiled document
     * @param array $properties The property values that MUST be applied to the object. This list of properties SHOULD
     *     only contain properties whose values differ from the source document. The array key is the property name
     *     the value is the property value.
     * @param VersioningState $versioningState An enumeration specifying what the versioning state of the newly-created
     *     object MUST be. Valid values are:
     *      <code>none</code>
     *          (default, if the object-type is not versionable) The document MUST be created as a non-versionable
     *          document.
     *     <code>checkedout</code>
     *          The document MUST be created in the checked-out state. The checked-out document MAY be
     *          visible to other users.
     *     <code>major</code>
     *          (default, if the object-type is versionable) The document MUST be created as a major version.
     *     <code>minor</code>
     *          The document MUST be created as a minor version.
     * @param PolicyInterface[] $policies A list of policy ids that MUST be applied to the newly-created document
     *     object.
     * @param AceInterface[] $addAces A list of ACEs that MUST be added to the newly-created document object, either
     *     using the ACL from folderId if specified, or being applied if no folderId is specified.
     * @param AceInterface[] $removeAces A list of ACEs that MUST be removed from the newly-created document object,
     *     either using the ACL from folderId if specified, or being ignored if no folderId is specified.
     * @param OperationContextInterface $context
     * @return DocumentInterface the new document object or <code>null</code> if the parameter <code>context</code> was
     *     set to <code>null</code>
     */
    public function copy(
        ObjectIdInterface $targetFolderId,
        $properties = array(),
        VersioningState $versioningState = null,
        array $policies = array(),
        array $addAces = array(),
        array $removeAces = array(),
        OperationContextInterface $context = null
    ) {
        try {
            $newObjectId = $this->getSession()->createDocumentFromSource(
                $this,
                $properties,
                $targetFolderId,
                $versioningState,
                $policies,
                $addAces,
                $removeAces
            );
        } catch (CmisNotSupportedException $notSupportedException) {
            $newObjectId = $this->copyViaClient(
                $targetFolderId,
                $properties,
                $versioningState,
                $policies,
                $addAces,
                $removeAces
            );
        }

        return $this->getNewlyCreatedObject($newObjectId, $context);
    }

    /**
     * Copies the document manually. The content is streamed from the repository and back.
     *
     * @param ObjectIdInterface $targetFolderId the ID of the target folder, null to create an unfiled document
     * @param array $properties The property values that MUST be applied to the object. This list of properties SHOULD
     *     only contain properties whose values differ from the source document. The array key is the property name
     *     the value is the property value.
     * @param VersioningState $versioningState An enumeration specifying what the versioning state of the newly-created
     *     object MUST be. Valid values are:
     *      <code>none</code>
     *          (default, if the object-type is not versionable) The document MUST be created as a non-versionable
     *          document.
     *     <code>checkedout</code>
     *          The document MUST be created in the checked-out state. The checked-out document MAY be
     *          visible to other users.
     *     <code>major</code>
     *          (default, if the object-type is versionable) The document MUST be created as a major version.
     *     <code>minor</code>
     *          The document MUST be created as a minor version.
     * @param PolicyInterface[] $policies A list of policy ids that MUST be applied to the newly-created document
     *     object.
     * @param AceInterface[] $addAces A list of ACEs that MUST be added to the newly-created document object, either
     *     using the ACL from folderId if specified, or being applied if no folderId is specified.
     * @param AceInterface[] $removeAces A list of ACEs that MUST be removed from the newly-created document object,
     *     either using the ACL from folderId if specified, or being ignored if no folderId is specified.
     * @return ObjectIdInterface The id of the newly-created document.
     */
    protected function copyViaClient(
        ObjectIdInterface $targetFolderId,
        $properties = array(),
        VersioningState $versioningState = null,
        array $policies = array(),
        array $addAces = array(),
        array $removeAces = array()
    ) {
        $newProperties = array();

        $allPropertiesContext = $this->getSession()->createOperationContext();
        $allPropertiesContext->setFilterString('*');
        $allPropertiesContext->setIncludeAcls(false);
        $allPropertiesContext->setIncludeAllowableActions(false);
        $allPropertiesContext->setIncludePathSegments(false);
        $allPropertiesContext->setIncludePolicies(false);
        $allPropertiesContext->setIncludeRelationships(IncludeRelationships::cast(IncludeRelationships::NONE));
        $allPropertiesContext->setRenditionFilterString(Constants::RENDITION_NONE);

        /** @var DocumentInterface $allPropertiesDocument */
        $allPropertiesDocument = $this->getSession()->getObject($this, $allPropertiesContext);

        foreach ($allPropertiesDocument->getProperties() as $property) {
            if (Updatability::cast(Updatability::READWRITE)->equals($property->getDefinition()->getUpdatability())
                || Updatability::cast(Updatability::ONCREATE)->equals($property->getDefinition()->getUpdatability())
            ) {
                $newProperties[$property->getId()] = $property->getValue();
            }
        }

        $newProperties = array_merge($newProperties, $properties);
        $contentStream = $allPropertiesDocument->getContentStream();

        return $this->getSession()->createDocument(
            $newProperties,
            $targetFolderId,
            $contentStream,
            $versioningState,
            $policies,
            $addAces,
            $removeAces
        );
    }

    /**
     * Deletes this document and all its versions.
     */
    public function deleteAllVersions()
    {
        $this->delete(true);
    }

    /**
     * Removes the current content stream from the document and refreshes this object afterwards.
     *
     * @param boolean $refresh if this parameter is set to <code>true</code>, this object will be refreshed after the
     *     content stream has been deleted
     * @return DocumentInterface|null the updated document, or null if the repository did not return an object ID
     */
    public function deleteContentStream($refresh = true)
    {
        $newObjectId = $this->getId();
        $changeToken = $this->getPropertyValue(PropertyIds::CHANGE_TOKEN);

        $this->getBinding()->getObjectService()->deleteContentStream(
            $this->getRepositoryId(),
            $newObjectId,
            $changeToken
        );

        if ($refresh === true) {
            $this->refresh();
        }

        if ($newObjectId === null) {
            return null;
        }

        return $this->getSession()->getObject(
            $this->getSession()->createObjectId($newObjectId),
            $this->getCreationContext()
        );
    }

    /**
     * Fetches all versions of this document using the given OperationContext.
     * The behavior of this method is undefined if the document is not versionable
     * and can be different for each repository.
     *
     * @param OperationContextInterface $context
     * @return DocumentInterface[]
     */
    public function getAllVersions(OperationContextInterface $context = null)
    {
        $context = $this->ensureContext($context);
        $versions = $this->getBinding()->getVersioningService()->getAllVersions(
            $this->getRepositoryId(),
            $this->getId(),
            $this->getVersionSeriesId(),
            $context->getQueryFilterString(),
            $context->isIncludeAllowableActions(),
            null
        );

        $objectFactory = $this->getSession()->getObjectFactory();
        $result = array();
        if (count($versions) !== null) {
            foreach ($versions as $objectData) {
                $document = $objectFactory->convertObject($objectData, $context);
                if (!(!$document instanceof DocumentInterface)) {
                    // this should never happen
                    continue;
                }
                $result[] = $document;
            }
        }

        return $result;
    }

    /**
     * Retrieves the content stream that is associated with the given stream ID.
     * This is usually a rendition of the document.
     *
     * @param string $streamId the stream ID
     * @param integer $offset the offset of the stream or null to read the stream from the beginning
     * @param integer $length the maximum length of the stream or null to read to the end of the stream
     * @return StreamInterface|null the content stream, or null if no content is associated with this stream ID
     */
    public function getContentStream($streamId = null, $offset = null, $length = null)
    {
        return $this->getSession()->getContentStream($this, $streamId, $offset, $length);
    }

    /**
     * Fetches the latest major or minor version of this document using the given OperationContext.
     *
     * @param boolean $major if true the latest major version will be returned,
     * otherwise the very last version will be returned
     * @param OperationContextInterface|null $context
     * @return DocumentInterface the latest document object
     */
    public function getObjectOfLatestVersion($major, OperationContextInterface $context = null)
    {
        $context = $this->ensureContext($context);

        return $this->getSession()->getLatestDocumentVersion($this, $major, $context);
    }

    /**
     * Sets a new content stream for the document. If the repository created a new version,
     * the object ID of this new version is returned. Otherwise the object ID of the current document is returned.
     * The stream in contentStream is consumed but not closed by this method.
     *
     * @param StreamInterface $contentStream the content stream
     * @param boolean $overwrite if this parameter is set to false and the document already has content,
     * the repository throws a CmisContentAlreadyExistsException
     * @param boolean $refresh if this parameter is set to true, this object will be refreshed
     * after the new content has been set
     * @return ObjectIdInterface|null the updated object ID, or null if the repository did not return an object ID
     */
    public function setContentStream(StreamInterface $contentStream, $overwrite, $refresh = true)
    {
        $newObjectId = $this->getId();
        $changeToken = $this->getPropertyValue(PropertyIds::CHANGE_TOKEN);

        $this->getBinding()->getObjectService()->setContentStream(
            $this->getRepositoryId(),
            $newObjectId,
            $this->getObjectFactory()->convertContentStream($contentStream),
            $overwrite,
            $changeToken
        );

        if ($refresh === true) {
            $this->refresh();
        }

        if ($newObjectId === null) {
            return null;
        }

        return $this->getSession()->createObjectId($newObjectId);
    }

    /**
     * Returns the checkin comment (CMIS property cmis:checkinComment).
     *
     * @return string|null the checkin comment of this version or null if the property hasn't been requested,
     * hasn't been provided by the repository, or the property value isn't set
     */
    public function getCheckinComment()
    {
        return $this->getPropertyValue(PropertyIds::CHECKIN_COMMENT);
    }

    /**
     * Returns the content stream filename or null if the document has no content
     * (CMIS property cmis:contentStreamFileName).
     *
     * @return string|null the content stream filename of this document or null if the property hasn't been requested,
     * hasn't been provided by the repository, or the document has no content
     */
    public function getContentStreamFileName()
    {
        return $this->getPropertyValue(PropertyIds::CONTENT_STREAM_FILE_NAME);
    }

    /**
     * Returns the content hashes or null if the document has no content (CMIS property cmis:contentStreamHash).
     *
     * @return ContentStreamHashInterface[]|null the list of content hashes or null if the property hasn't been
     * requested, hasn't been provided by the repository, or the document has no content
     */
    public function getContentStreamHashes()
    {
        return null;
        // TODO: Implement getContentStreamHashes() method.
        // TODO: Check if ContentStreamHashInterface is required and has to be implemented
    }

    /**
     * Returns the content stream ID or null if the document has no content (CMIS property cmis:contentStreamId).
     *
     * @return string|null the content stream ID of this document or null if the property hasn't been requested,
     * hasn't been provided by the repository, or the document has no content
     */
    public function getContentStreamId()
    {
        return $this->getPropertyValue(PropertyIds::CONTENT_STREAM_ID);
    }

    /**
     * Returns the content stream length or <code>null</code> if the document has no content (CMIS property
     * cmis:contentStreamLength).
     *
     * @return integer the content stream length of this document or <code>null</code> if the property hasn't been
     *     requested, hasn't been provided by the repository, or the document has no content
     */
    public function getContentStreamLength()
    {
        return $this->getPropertyValue(PropertyIds::CONTENT_STREAM_LENGTH);
    }

    /**
     * Returns the content stream MIME type or null if the document has no content
     * (CMIS property cmis:contentStreamMimeType).
     *
     * @return string|null the content stream MIME type of this document or null if the property hasn't been requested,
     * hasn't been provided by the repository, or the document has no content
     */
    public function getContentStreamMimeType()
    {
        return $this->getPropertyValue(PropertyIds::CONTENT_STREAM_MIME_TYPE);
    }

    /**
     * Returns the version label (CMIS property cmis:versionLabel).
     *
     * @return string|null the version label of the document or null if the property hasn't been requested,
     * hasn't been provided by the repository, or the property value isn't set
     */
    public function getVersionLabel()
    {
        return $this->getPropertyValue(PropertyIds::VERSION_LABEL);
    }

    /**
     * Returns the user who checked out this version series (CMIS property cmis:versionSeriesCheckedOutBy).
     *
     * @return string|null the user who checked out this version series or null if the property hasn't been requested,
     * hasn't been provided by the repository, or the property value isn't set
     */
    public function getVersionSeriesCheckedOutBy()
    {
        return $this->getPropertyValue(PropertyIds::VERSION_SERIES_CHECKED_OUT_BY);
    }

    /**
     * Returns the PWC ID of this version series (CMIS property cmis:versionSeriesCheckedOutId).
     * Some repositories provided this value only to the user who checked out the version series.
     *
     * @return string|null the PWC ID of this version series or null if the property hasn't been requested,
     * hasn't been provided by the repository, or the property value isn't set
     */
    public function getVersionSeriesCheckedOutId()
    {
        return $this->getPropertyValue(PropertyIds::VERSION_SERIES_CHECKED_OUT_ID);
    }

    /**
     * Returns the version series ID (CMIS property cmis:versionSeriesId).
     *
     * @return string|null the version series ID of the document or null if the property hasn't been requested,
     * hasn't been provided by the repository, or the property value isn't set
     */
    public function getVersionSeriesId()
    {
        return $this->getPropertyValue(PropertyIds::VERSION_SERIES_ID);
    }

    /**
     * Returns true if this document is immutable (CMIS property cmis:isImmutable).
     *
     * @return boolean|null the immutable flag of the document or null if the property hasn't been requested,
     * hasn't been provided by the repository, or the property value isn't set
     */
    public function isImmutable()
    {
        return $this->getPropertyValue(PropertyIds::IS_IMMUTABLE);
    }

    /**
     * Returns true if this document is the latest version (CMIS property cmis:isLatestVersion).
     *
     * @return boolean|null the latest version flag of the document or null if the property hasn't been requested,
     * hasn't been provided by the repository, or the property value isn't set
     */
    public function isLatestMajorVersion()
    {
        return $this->getPropertyValue(PropertyIds::IS_LATEST_MAJOR_VERSION);
    }

    /**
     * Returns true if this document is the latest version (CMIS property cmis:isLatestVersion).
     *
     * @return boolean|null the latest version flag of the document or null if the property hasn't been requested,
     * hasn't been provided by the repository, or the property value isn't set
     */
    public function isLatestVersion()
    {
        return $this->getPropertyValue(PropertyIds::IS_LATEST_VERSION);
    }

    /**
     * Returns true if this document is a major version (CMIS property cmis:isMajorVersion).
     *
     * @return boolean|null the major version flag of the document or null if the property hasn't been requested,
     * hasn't been provided by the repository, or the property value isn't set
     */
    public function isMajorVersion()
    {
        return $this->getPropertyValue(PropertyIds::IS_MAJOR_VERSION);
    }

    /**
     * Returns true if this document is the PWC (CMIS property cmis:isPrivateWorkingCopy).
     *
     * @return boolean|null the PWC flag of the document or null if the property hasn't been requested,
     * hasn't been provided by the repository, or the property value isn't set
     */
    public function isPrivateWorkingCopy()
    {
        return $this->getPropertyValue(PropertyIds::IS_PRIVATE_WORKING_COPY);
    }

    /**
     * Returns true if this version series is checked out (CMIS property cmis:isVersionSeriesCheckedOut).
     *
     * @return boolean|null the version series checked out flag of the document or null if the property hasn't been
     * requested, hasn't been provided by the repository, or the property value isn't set
     */
    public function isVersionSeriesCheckedOut()
    {
        return $this->getPropertyValue(PropertyIds::IS_VERSION_SERIES_CHECKED_OUT);
    }
}