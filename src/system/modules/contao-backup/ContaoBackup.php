<?php

/**
 * Backup Extension for Contao Open Source CMS
 *
 * @package ContaoBackup
 * @link    http://www.infinitysoft.de
 * @license http://www.gnu.org/licenses/lgpl-3.0.html LGPL
 */

/**
 * Class ContaoBackup
 *
 * @package ContaoBackup
 * @author  Tristan Lins <tristan.lins@infinitysoft.de>
 */
class ContaoBackup extends Backend implements executable
{
	public function run()
	{
		$strTemplate = 'be_contao_backup';
		$blnActive   = $this->isActive();

		if (preg_match('#^contao-backup-\d+\.zip$#', $this->Input->get('contao_backup_delete'))) {
			$strFile = TL_ROOT . '/system/backups/' . $this->Input->get('contao_backup_delete');
			if (file_exists($strFile)) {
				unlink($strFile);
			}
			$this->redirect('contao/main.php?do=maintenance#tl_maintenance_contao_backup');
		}

		if (preg_match('#^contao-backup-\d+\.zip$#', $this->Input->get('contao_backup_fetch'))) {
			$strFile = 'system/backups/' . $this->Input->get('contao_backup_fetch');
			if (file_exists(TL_ROOT . '/' . $strFile)) {
				// Hack to allow sendFileToBrowser send the file from system/backups !
				$GLOBALS['TL_CONFIG']['uploadPath'] = 'system/backups';

				// Hack to allow sendFileToBrowser send zip files !
				$GLOBALS['TL_CONFIG']['allowedDownload'] = 'zip';

				// send zip to browser
				$this->sendFileToBrowser($strFile);
			}
			$this->redirect('contao/main.php?do=maintenance#tl_maintenance_contao_backup');
		}

		if (isset($_SESSION['CONTAO_BACKUP_RUN'])) {
			$strTemplate = 'be_contao_backup_run';
		}

		// Purge the resources
		if ($blnActive) {
			if (!isset($_SESSION['CONTAO_BACKUP_RUN'])) {
				$_SESSION['CONTAO_BACKUP_RUN'] = array('step' => 'collect');
			}

			switch ($_SESSION['CONTAO_BACKUP_RUN']['step']) {
				case 'collect':
					$_SESSION['CONTAO_BACKUP_RUN']['directories'] = array();
					$_SESSION['CONTAO_BACKUP_RUN']['files']       = array();

					$iterator = new RecursiveIteratorIterator(
						new RecursiveDirectoryIterator(TL_ROOT,
							FilesystemIterator::CURRENT_AS_FILEINFO));

					/** @var SplFileInfo $file */
					foreach ($iterator as $file) {
						if (!$this->isValidFile($file)) {
							continue;
						}

						$strName = substr($file->getRealPath(), strlen(TL_ROOT) + 1);

						if (!$strName) {
							continue;
						}

						if ($file->isDir()) {
							$_SESSION['CONTAO_BACKUP_RUN']['directories'][] = $strName;
						} else {
							$_SESSION['CONTAO_BACKUP_RUN']['files'][] = $strName;
						}
					}

					sort($_SESSION['CONTAO_BACKUP_RUN']['directories']);
					$_SESSION['CONTAO_BACKUP_RUN']['directories'] = array_unique($_SESSION['CONTAO_BACKUP_RUN']['directories']);

					sort($_SESSION['CONTAO_BACKUP_RUN']['files']);

					$_SESSION['CONTAO_BACKUP_RUN']['step'] = 'create';
					$_SESSION['CONTAO_BACKUP_CONFIRM']     = sprintf($GLOBALS['TL_LANG']['tl_maintenance']['contaoBackupFilesCollected'],
						count($_SESSION['CONTAO_BACKUP_RUN']['files']), count($_SESSION['CONTAO_BACKUP_RUN']['directories']));
					break;

				case 'create':
					$_SESSION['CONTAO_BACKUP_RUN']['zip'] = 'system/backups/contao-backup-' . $this->parseDate('YmdHis') . '.zip';

					// define hostname as zip prefix
					$strPrefix = $this->Environment->httpHost . '/';

					// create the zip file
					$objZipArchive = new ZipArchive();
					$objZipArchive->open(TL_ROOT . '/' . $_SESSION['CONTAO_BACKUP_RUN']['zip'], ZipArchive::CREATE);

					// create directories in the zip
					$objZipArchive->addEmptyDir($strPrefix);
					foreach ($_SESSION['CONTAO_BACKUP_RUN']['directories'] as $file) {
						$objZipArchive->addEmptyDir($strPrefix . $file);
					}

					// append all files to the zip
					foreach ($_SESSION['CONTAO_BACKUP_RUN']['files'] as $file) {
						$objZipArchive->addFile(TL_ROOT . '/' . $file, $strPrefix . $file);
					}

					// finalize the zip file
					$objZipArchive->close();

					$_SESSION['CONTAO_BACKUP_RUN']['step'] = 'send';
					$_SESSION['CONTAO_BACKUP_CONFIRM']     = sprintf($GLOBALS['TL_LANG']['tl_maintenance']['contaoBackupZipCreated'],
						$this->getReadableSize(filesize(TL_ROOT . '/' . $_SESSION['CONTAO_BACKUP_RUN']['zip'])));
					break;

				case 'send':
					// remember the generated zip filename
					$strZip = $_SESSION['CONTAO_BACKUP_RUN']['zip'];

					// unset the session variable
					unset($_SESSION['CONTAO_BACKUP_RUN']);

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
		$iterator   = new RegexIterator(
			new DirectoryIterator(TL_ROOT . '/system/backups'),
			'#^contao-backup-\d+\.zip$#');
		foreach ($iterator as $file) {
			$arrBackups[$file->getFilename()] = $this->getReadableSize($file->getSize());
		}
		ksort($arrBackups);

		$objTemplate           = new BackendTemplate($strTemplate);
		$objTemplate->isActive = $blnActive;
		$objTemplate->action   = ampersand($this->Environment->request);
		$objTemplate->backups  = $arrBackups;

		// Confirmation message
		if ($_SESSION['CONTAO_BACKUP_CONFIRM'] != '') {
			$objTemplate->cacheMessage         = sprintf('<p class="tl_confirm">%s</p>' . "\n", $_SESSION['CONTAO_BACKUP_CONFIRM']);
			$_SESSION['CONTAO_BACKUP_CONFIRM'] = '';
		}

		return $objTemplate->parse();
	}

	public function isActive()
	{
		return ($this->Input->post('FORM_SUBMIT') == 'tl_contao_backup');
	}

	public function isValidFile(SplFileInfo $file)
	{
		if (preg_match('#/system/(backups|html|images|scripts|tmp)/#S', $file->getRealPath())) {
			return false;
		}

		return true;
	}
}