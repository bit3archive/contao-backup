<?php

/**
 * Full Backup Extension for Contao Open Source CMS
 *
 * @package FullBackup
 * @link    http://www.infinitysoft.de
 * @license http://www.gnu.org/licenses/lgpl-3.0.html LGPL
 */

if (isset($_SESSION['FULLBACKUP_RUN'])) {
	/**
	 * Extreme primitive implementation of a unmodifiable array class.
	 *
	 * @package FullBackup
	 * @author  Tristan Lins <tristan.lins@infinitysoft.de>
	 */
	class UnmodifiableArray implements IteratorAggregate, ArrayAccess, Countable
	{
		protected $array;

		public function __construct(array $array)
		{
			$this->array = $array;
		}

		public function getIterator()
		{
			return new ArrayIterator($this->array);
		}

		public function offsetExists($offset)
		{
			return isset($this->array[$offset]);
		}

		public function offsetGet($offset)
		{
			return $this->array[$offset];
		}

		public function offsetSet($offset, $value) {}

		public function offsetUnset($offset) {}

		public function count()
		{
			return count($this->array);
		}
	}

	$GLOBALS['TL_MAINTENANCE'] = new UnmodifiableArray(array('FullBackup'));
}
else {
	$GLOBALS['TL_MAINTENANCE'][] = 'FullBackup';
}
