<?

class TrumanException extends Exception {

	public static function throwNew($context, $msg = '', Exception $inner_exception = null) {
		$code  = is_null($inner_exception) ? 0 : $inner_exception->getCode();
		$msg   = strlen($msg) ? "({$context}) {$msg}" : "({$context})";
		throw new TrumanException($msg, $code, $inner_exception);
	}

}