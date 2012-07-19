<?php

/**
 * Full Backup Extension for Contao Open Source CMS
 *
 * @package FullBackup
 * @link    http://www.infinitysoft.de
 * @license http://www.gnu.org/licenses/lgpl-3.0.html LGPL
 */

/**
 * Class FullBackup
 *
 * @package FullBackup
 * @author  Tristan Lins <tristan.lins@infinitysoft.de>
 */
class FullBackup extends Backend implements executable
{
	public function run()
	{
		$strTemplate = 'be_fullbackup';
		$blnActive   = $this->isActive();

		if (preg_match('#^fullbackup-\d+\.zip$#', $this->Input->get('fullbackupDelete'))) {
			$strFile = TL_ROOT . '/system/backups/' . $this->Input->get('fullbackupDelete');
			if (file_exists($strFile)) {
				unlink($strFile);
			}
			$this->redirect('contao/main.php?do=maintenance#tl_maintenance_fullbackup');
		}

		if (preg_match('#^fullbackup-\d+\.zip$#', $this->Input->get('fullbackupFetch'))) {
			$strFile ='system/backups/' . $this->Input->get('fullbackupFetch');
			if (file_exists( TL_ROOT . '/' . $strFile)) {
				// Hack to allow sendFileToBrowser send the file from system/backups !
				$GLOBALS['TL_CONFIG']['uploadPath'] = 'system/backups';

				// Hack to allow sendFileToBrowser send zip files !
				$GLOBALS['TL_CONFIG']['allowedDownload'] = 'zip';

				// send zip to browser
				$this->sendFileToBrowser($strFile);
			}
			$this->redirect('contao/main.php?do=maintenance#tl_maintenance_fullbackup');
		}

		if (isset($_SESSION['FULLBACKUP_RUN'])) {
			$strTemplate = 'be_fullbackup_run';
		}

		// Purge the resources
		if ($blnActive) {
			if (!isset($_SESSION['FULLBACKUP_RUN'])) {
				$_SESSION['FULLBACKUP_RUN'] = array('step' => 'collect');
			}

			switch ($_SESSION['FULLBACKUP_RUN']['step']) {
				case 'collect':
					$_SESSION['FULLBACKUP_RUN']['directories'] = array();
					$_SESSION['FULLBACKUP_RUN']['files']       = array();

					$iterator = new CallbackFilterIterator(
						new RecursiveIteratorIterator(
							new RecursiveDirectoryIterator(TL_ROOT,
								FilesystemIterator::CURRENT_AS_FILEINFO)),
						array($this, 'isValidFile'));

					/** @var SplFileInfo $file */
					foreach ($iterator as $file) {
						$strName = substr($file->getRealPath(), strlen(TL_ROOT) + 1);

						if (!$strName) {
							continue;
						}

						if ($file->isDir()) {
							$_SESSION['FULLBACKUP_RUN']['directories'][] = $strName;
						} else {
							$_SESSION['FULLBACKUP_RUN']['files'][] = $strName;
						}
					}

					sort($_SESSION['FULLBACKUP_RUN']['directories']);
					$_SESSION['FULLBACKUP_RUN']['directories'] = array_unique($_SESSION['FULLBACKUP_RUN']['directories']);

					sort($_SESSION['FULLBACKUP_RUN']['files']);

					$_SESSION['FULLBACKUP_RUN']['step'] = 'create';
					$_SESSION['FULLBACKUP_CONFIRM']     = sprintf($GLOBALS['TL_LANG']['tl_maintenance']['fullBackupFilesCollected'],
						count($_SESSION['FULLBACKUP_RUN']['files']), count($_SESSION['FULLBACKUP_RUN']['directories']));
					break;

				case 'create':
					$_SESSION['FULLBACKUP_RUN']['zip'] = 'system/backups/fullbackup-' . $this->parseDate('YmdHis') . '.zip';

					// define hostname as zip prefix
					$strPrefix = $this->Environment->httpHost . '/';

					// create the zip file
					$objZipArchive = new ZipArchive();
					$objZipArchive->open(TL_ROOT . '/' . $_SESSION['FULLBACKUP_RUN']['zip'], ZipArchive::CREATE);

					// create directories in the zip
					$objZipArchive->addEmptyDir($strPrefix);
					foreach ($_SESSION['FULLBACKUP_RUN']['directories'] as $file) {
						$objZipArchive->addEmptyDir($strPrefix . $file);
					}

					// append all files to the zip
					foreach ($_SESSION['FULLBACKUP_RUN']['files'] as $file) {
						$objZipArchive->addFile(TL_ROOT . '/' . $file, $strPrefix . $file);
					}

					// finalize the zip file
					$objZipArchive->close();

					$_SESSION['FULLBACKUP_RUN']['step'] = 'send';
					$_SESSION['FULLBACKUP_CONFIRM']     = sprintf($GLOBALS['TL_LANG']['tl_maintenance']['fullBackupZipCreated'],
						$this->getReadableSize(filesize(TL_ROOT . '/' . $_SESSION['FULLBACKUP_RUN']['zip'])));
					break;

				case 'send':
					// remember the generated zip filename
					$strZip = $_SESSION['FULLBACKUP_RUN']['zip'];

					// unset the session variable
					unset($_SESSION['FULLBACKUP_RUN']);

					// Hack to allow sendFileToBrowser send the file from system/backups !
					$GLOBALS['TL_CONFIG']['uploadPath'] = 'system/backups';

					// Hack to allow sendFileToBrowser send zip files !
					$GLOBALS['TL_CONFIG']['allowedDownload'] = 'zip';

					// send zip to browser
					$this->sendFileToBrowser($strZip);
					break;
			}

			$this->reload();
		}

		$arrBackups = array();
		$iterator   = new DirectoryIterator(TL_ROOT . '/system/backups');
		foreach ($iterator as $file) {
			if ($file->isFile() && $file->getFilename() != '.htaccess') {
				$arrBackups[$file->getFilename()] = $this->getReadableSize($file->getSize());
			}
		}
		ksort($arrBackups);

		$objTemplate           = new BackendTemplate($strTemplate);
		$objTemplate->isActive = $blnActive;
		$objTemplate->action   = ampersand($this->Environment->request);
		$objTemplate->backups  = $arrBackups;

		// Confirmation message
		if ($_SESSION['FULLBACKUP_CONFIRM'] != '') {
			$objTemplate->cacheMessage      = sprintf('<p class="tl_confirm">%s</p>' . "\n", $_SESSION['FULLBACKUP_CONFIRM']);
			$_SESSION['FULLBACKUP_CONFIRM'] = '';
		}

		return $objTemplate->parse();
	}

	public function isActive()
	{
		return ($this->Input->post('FORM_SUBMIT') == 'tl_fullbackup');
	}

	public function isValidFile(SplFileInfo $file)
	{
		if (preg_match('#/system/(backups|html|images|scripts|tmp)/#S', $file->getRealPath())) {
			return false;
		}

		return true;
	}
}