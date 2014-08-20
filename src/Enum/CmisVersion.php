<?php
namespace Dkd\PhpCmis\Enum;

/**
 * This file is part of php-cmis-lib.
 *
 * (c) Sascha Egerer <sascha.egerer@dkd.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Eloquent\Enumeration\AbstractEnumeration;

/**
 * CMIS Version enum
 */
final class CmisVersion extends AbstractEnumeration
{
    const CMIS_1_0 = '1.0';
    const CMIS_1_1 = '1.1';
}