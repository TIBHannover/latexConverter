<?php
/**
 * @file plugins/generic/latexConverter/classes/Action/Convert.php
 *
 * Copyright (c) 2023+ TIB Hannover
 * Copyright (c) 2023+ Gazi Yücel
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Convert
 * @ingroup plugins_generic_latexconverter
 *
 * @brief Action Convert for the Handler
 */

namespace APP\plugins\generic\latexConverter\classes\Workflow;

use NotificationManager;
use APP\plugins\generic\latexConverter\classes\Constants;
use APP\plugins\generic\latexConverter\classes\Helpers\FileSystemHelper;
use APP\plugins\generic\latexConverter\classes\Helpers\SubmissionFileHelper;
use APP\plugins\generic\latexConverter\LatexConverterPlugin;
use Config;
use JSONMessage;
use PrivateFileManager;
use Services;

class Convert
{
    /** @var LatexConverterPlugin */
    protected LatexConverterPlugin $plugin;

    /** @var PrivateFileManager */
    protected PrivateFileManager $fileManager;

    /** @var NotificationManager */
    protected NotificationManager $notificationManager;

    /** @var mixed Request */
    protected mixed $request;

    /** @var object Submission */
    protected object $submission;

    /** @var string */
    protected string $timeStamp;

    /** @var int */
    protected int $submissionId;

    /** @var object SubmissionFile */
    protected object $submissionFile;

    /** @var int */
    protected int $submissionFileId;

    /**
     * Absolute path to the work directory, e.g. /tmp/latexConverter_20230701_150101
     *
     * @var string
     */
    protected string $workingDirAbsolutePath;

    /**
     * Path to directory for files of this submission, e.g. journals/1/articles/51
     *
     * @var string
     */
    protected string $submissionFilesRelativeDir;

    /**
     * Absolute path to ojs files directory, e.g. /var/www/ojs_files
     *
     * @var string
     */
    protected string $ojsFilesAbsoluteBaseDir;

    /**
     * The dependent files for this submission id
     *
     * @var array
     */
    protected array $submissionFileMain;

    /**
     * The dependent files for this submission id
     *
     * @var array
     */
    protected array $submissionFileDependents;

    /**
     * The name of the main tex file, e.g. main.tex
     *
     * @var string
     */
    protected string $mainFileName = '';

    /**
     * The names of the dependent files, e.g. [ 'image1.png', ... ]
     *
     * @var string[]
     */
    protected array $dependentFileNames = [];

    /**
     * Generated PDF file, e.g. main.pdf
     *
     * @var string
     */
    protected string $pdfFile = '';

    /**
     * Name of the log file, e.g. main.log
     *
     * @var string
     */
    protected string $logFile = '';

    /**
     * Absolute path to the latex executable
     *
     * @var string
     */
    protected string $latexExe = '';

    function __construct(LatexConverterPlugin &$plugin)
    {
        $this->timeStamp = date('Ymd_His');

        $this->plugin = &$plugin;

        $this->notificationManager = new NotificationManager();

        $this->request = $this->plugin->getRequest();

        $this->submissionFileId = (int)$this->request->getUserVar('submissionFileId');
        $this->submissionFile = Services::get('submissionFile')->get($this->submissionFileId);

        $this->mainFileName =
            $this->submissionFile->getData('name')[$this->submissionFile->getData('locale')];

        $this->pdfFile = str_replace('.' . Constants::TEX_EXTENSION,
            '.' . Constants::PDF_EXTENSION, $this->mainFileName);

        $this->logFile = str_replace('.' . Constants::TEX_EXTENSION,
            '.' . Constants::LOG_EXTENSION, $this->mainFileName);

        $this->submissionId = (int)$this->submissionFile->getData('submissionId');
        $this->submission = Services::get('submission')->get($this->submissionId);

        $this->workingDirAbsolutePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR .
            LATEX_CONVERTER_PLUGIN_NAME . '_' . $this->timeStamp . '_' . uniqid();

        $this->submissionFilesRelativeDir = Services::get('submissionFile')
            ->getSubmissionDir($this->submission->getData('contextId'), $this->submissionId);

        $this->ojsFilesAbsoluteBaseDir = Config::getVar('files', 'files_dir');

        $this->latexExe = $this->plugin->getSetting($this->request->getContext()->getId(),
            Constants::SETTING_LATEX_PATH_EXECUTABLE);
    }

    /**
     * Main entry point
     *
     * @return JSONMessage
     */
    public function process(): JSONMessage
    {
        // check if latex executable path configured
        if (empty($this->latexExe)) {
            $this->notificationManager->createTrivialNotification(
                $this->request->getUser()->getId(),
                NOTIFICATION_TYPE_ERROR,
                array('contents' => __('plugins.generic.latexConverter.executable.notConfigured')));
            return $this->defaultResponse();
        }

        // create working directory
        if (!mkdir($this->workingDirAbsolutePath, 0777, true)) {
            $this->notificationManager->createTrivialNotification(
                $this->request->getUser()->getId(),
                NOTIFICATION_TYPE_ERROR,
                array('contents' => __('plugins.generic.latexConverter.notification.defaultErrorOccurred')));
            return $this->defaultResponse();
        }

        // get submission file
        if (!$this->getSubmissionFileMain()) {
            $this->notificationManager->createTrivialNotification(
                $this->request->getUser()->getId(),
                NOTIFICATION_TYPE_ERROR,
                array('contents' => __('plugins.generic.latexConverter.notification.defaultErrorOccurred')));
            return $this->defaultResponse();
        }

        // get dependent files
        if (!$this->getSubmissionFileDependents()) {
            $this->notificationManager->createTrivialNotification(
                $this->request->getUser()->getId(),
                NOTIFICATION_TYPE_ERROR,
                array('contents' => __('plugins.generic.latexConverter.notification.defaultErrorOccurred')));
            return $this->defaultResponse();
        }

        // get files and copy file to working directory
        if (!$this->copyFilesToWorkingDir()) {
            $this->notificationManager->createTrivialNotification(
                $this->request->getUser()->getId(),
                NOTIFICATION_TYPE_ERROR,
                array('contents' => __('plugins.generic.latexConverter.notification.defaultErrorOccurred')));
            return $this->defaultResponse();
        }

        // do the conversion to pdf
        if (!$this->convertToPdf()) {
            $this->notificationManager->createTrivialNotification(
                $this->request->getUser()->getId(),
                NOTIFICATION_TYPE_ERROR,
                array('contents' => __('plugins.generic.latexConverter.notification.defaultErrorOccurred')));
            return $this->defaultResponse();
        }

        // check if pdf file exists and add this as main file
        if (file_exists($this->workingDirAbsolutePath . DIRECTORY_SEPARATOR . $this->pdfFile)) {
            if (!$this->addFiles($this->pdfFile,
                str_replace('.' . Constants::TEX_EXTENSION, '', $this->mainFileName)))
                return $this->defaultResponse();
        } // no pdf file found, check if log file exists and add this as main file
        elseif (file_exists($this->workingDirAbsolutePath . DIRECTORY_SEPARATOR . $this->logFile)) {
            if (!$this->addFiles($this->logFile,
                str_replace('.' . Constants::TEX_EXTENSION, '', $this->mainFileName)))
                return $this->defaultResponse();
        } else {
            return $this->defaultResponse();
        }

        // all went well, return ok
        return $this->defaultResponse(true);
    }

    /**
     * Gets and returns the main file
     *
     * @return bool
     */
    private function getSubmissionFileMain(): bool
    {
        $this->submissionFileMain[] =
            Services::get('submissionFile')->get($this->submissionFileId);

        if (empty($this->submissionFileMain)) return false;

        return true;
    }

    /**
     * Get and return main and dependent files for this submissionFile
     *
     * @return bool
     */
    private function getSubmissionFileDependents(): bool
    {
        $allFiles = Services::get('submissionFile')->getMany([
            'assocIds' => [$this->submissionId],
            'submissionIds' => [$this->submissionId],
            'includeDependentFiles' => true
        ]);

        foreach ($allFiles as $file) {
            if ($file->getData('assocId') == $this->submissionFileId)
                $this->submissionFileDependents[] = $file;
        }

        if (empty($this->submissionFileDependents)) return false;

        return true;
    }

    /**
     * Get files of submission and copy to working dir
     *
     * @return bool
     */
    private function copyFilesToWorkingDir(): bool
    {
        // main file
        foreach ($this->submissionFileMain as $file) {
            copy(
                $this->ojsFilesAbsoluteBaseDir . DIRECTORY_SEPARATOR . $file->getData('path'),
                $this->workingDirAbsolutePath . DIRECTORY_SEPARATOR .
                $file->getData('name')[$file->getData('locale')]);
        }

        // dependent files
        foreach ($this->submissionFileDependents as $file) {
            $fromFile = $this->ojsFilesAbsoluteBaseDir . DIRECTORY_SEPARATOR . $file->getData('path');
            $toFile = $this->workingDirAbsolutePath . DIRECTORY_SEPARATOR .
                $file->getData('name')[$file->getData('locale')];

            if (!file_exists(dirname($toFile))) mkdir(dirname($toFile), 0777, true);

            copy($fromFile, $toFile);
        }

        return true;
    }

    /**
     * Convert LaTex file to pdf
     *
     * @return bool
     */
    private function convertToPdf(): bool
    {
        shell_exec("cd $this->workingDirAbsolutePath " .
            "&& $this->latexExe -no-shell-escape -interaction=nonstopmode $this->mainFileName 2>&1");

        return true;
    }

    /**
     * Add output files to submission
     *
     * @param string $fileToAdd
     * @param string $fileToAddWithoutExtension
     * @return bool
     */
    private function addFiles(string $fileToAdd, string $fileToAddWithoutExtension): bool
    {
        $files = array_map(
            'basename',
            glob($this->workingDirAbsolutePath . "/" . $fileToAddWithoutExtension . "*"));

        foreach ($files as $file)
            if ($file !== $this->mainFileName && $file !== $fileToAdd)
                $this->dependentFileNames[] = $file;

        $submissionFileHelper =
            new SubmissionFileHelper(
                $this->request,
                $this->submissionId,
                $this->submissionFile,
                $this->workingDirAbsolutePath,
                $this->submissionFilesRelativeDir,
                $fileToAdd,
                $this->dependentFileNames);

        if (!$submissionFileHelper->addMainFile()) return false;

        if (!empty($this->dependentFileNames))
            if (!$submissionFileHelper->addDependentFiles()) return false;

        return true;
    }

    /**
     * Default response; Only submissionId is returned as a JSONMessage
     *
     * @param bool $status
     * @return JSONMessage
     */
    private function defaultResponse(bool $status = false): JSONMessage
    {
        return new JSONMessage($status, ['submissionId' => $this->submissionId]);
    }

    function __destruct()
    {
        if (file_exists($this->workingDirAbsolutePath)) {
            FileSystemHelper::removeDirectoryAndContentsRecursively($this->workingDirAbsolutePath);
        }
    }
}
