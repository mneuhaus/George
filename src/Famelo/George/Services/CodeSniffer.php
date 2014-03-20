<?php
namespace Famelo\George\Services;

use Buzz\Browser;


/**
 *
 */
class CodeSniffer {

	/**
	 * @var string
	 */
	protected $standard;

	public function __construct($standard = 'TYPO3Flow') {
		$this->codeSniffer = new \PHP_CodeSniffer_CLI();
		\PHP_CodeSniffer::setConfigData('report_format', 'json');
		$this->standard = $standard;
	}

	public function sniff($file) {
		ob_start();
		$result = $this->codeSniffer->process(array(
			'files' => $file,
			'standard' => array($this->standard)
		));
		return json_decode(ob_get_clean());
	}
}

?>