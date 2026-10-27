<?php

namespace Magrathea\Exceptions;
use Magrathea\Exceptions\MagratheaException;

/**
* Class for Magrathea Model Errors
*/
class MagratheaModelException extends MagratheaException {
	public function __construct($message = "Error in Magrathea Model", $code = 0, ?\Exception $previous = null) {
			parent::__construct($message, $code, $previous);
	}
}
