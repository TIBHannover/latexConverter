<?php
/**
 * @file classes/Constants.php
 *
 * @copyright (c) 2021+ TIB Hannover
 * @copyright (c) 2021+ Gazi Yücel
 * @license Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Constants
 * @brief Constants
 */

namespace APP\plugins\generic\latexConverter\classes;

use Role;

class Constants
{
    public const zipFileType = 'application/zip';

    public const texFileType = 'text/x-tex';

    public const texExtension = 'tex';

    public const pdfExtension = 'pdf';

    public const logExtension = 'log';

    public const authorizedRoles = [ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR, ROLE_ID_ASSISTANT];

    public const settingKeyPathExecutable = 'LatexConverter_PathToExecutable';

    public const settingKeySupportsDependentFilesMimeTypes = 'LatexConverter_AuthorisedMimeTypes';

    public const extensions = [
        'tex' => ['tex'],
        'pdf' => ['pdf'],
        'log' => ['log'],
        'text' => ['txt'],
        'image' => ['gif', 'jpg', 'jpeg', 'png', 'jpe'],
        'html' => ['htm', 'html'],
        'style' => ['css']
    ];

    public const texMainFilename = 'main.' . self::texExtension;
}