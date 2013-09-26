<? namespace truman;

use truman\interfaces\LoggerContext;

class Drawer implements \JsonSerializable, LoggerContext {

	const KILLCODE = '__DRAWER_KILL__';

	const LOGGER_TYPE          = 'DRAWER';
	const LOGGER_EVENT_INIT    = 'INIT';
	const LOGGER_EVENT_EXIT    = 'EXIT';
	const LOGGER_EVENT_ERROR   = 'ERROR';
	const LOGGER_EVENT_FATAL   = 'FATAL';

	private $options, $data, $logger;
	private $original_memory_limit;
	private $original_time_limit;

	private static $_DEFAULT_OPTIONS = [
		'logger_options'     => [],
		'timeout'            => 0,
		'stream_input'       => STDIN,
		'stream_output'      => STDOUT,
	];

	public static function main(array $argv, array $options = []) {
		$reqs   = array_slice($argv, 1);
		$drawer = new Drawer($reqs, $options);
		register_shutdown_function([$drawer, 'shutdown']);
		exit($drawer->poll());
	}

	public function shutdown() {

		$status_code = 0;

		// something bad happened; let papa know
		if (isset($this->data)) {
			$error = error_get_last();
			$this->logger->log(self::LOGGER_EVENT_FATAL, $error);
			if (isset($error['message']{0}))
				$this->data['error'] = $error;
			if ($output = ob_get_clean())
				$this->data['output'] = $output;
			$this->data['runtime'] += microtime(true);
			$this->data['memory'] = Util::getMemoryUsage();

			$result = new Result(false, (object) $this->data);
			$this->result_log($result);
			$this->result_write($result);
			$status_code = 1;
		}

		$this->logger->log(self::LOGGER_EVENT_EXIT, $status_code);
		exit($status_code);

	}

	public function __construct(array $requirements = [], array $options = []) {
		$this->options = $options + self::$_DEFAULT_OPTIONS;
		$this->logger  = new Logger($this, $this->options['logger_options']);
		$this->original_memory_limit = ini_get('memory_limit');
		$this->original_time_limit = ini_get('max_execution_time');
		foreach ($requirements as $requirement)
			require_once $requirement;
		$this->logger->log(self::LOGGER_EVENT_INIT, $requirements);
	}

	function __toString() {
		$id = $this->getLoggerId();
		return "Drawer<{$id}>";
	}

	public function jsonSerialize() {
		return $this->__toString();
	}

	public function getLoggerType() {
		return self::LOGGER_TYPE;
	}

	public function getLoggerId() {
		return getmypid();
	}

	public function getLogger() {
		return $this->logger;
	}


	public function poll() {
		declare(ticks = 1);
		do $status = $this->tick();
		while($status < 0);
		return (int) $status;
	}

	public function tick() {

		$inputs = [$this->options['stream_input']];

		if (!stream_select($inputs, $i, $j, $this->options['timeout']))
			return -1;

		$input = fgets(reset($inputs));
		$buck  = Util::streamDataDecode($input);

		if (is_null($buck)) return -1;

		$valid = $buck instanceof Buck;
		if (!$valid) {
			$this->logger->log(self::LOGGER_EVENT_ERROR, $input);
			return -1;
		}

		$result = $this->execute($buck);

		$this->result_write($result);
		$data = $result->data();

		return isset($data->retval) && $data->retval === self::KILLCODE ? 0 : -1;

	}

	private function result_write(Result $result) {
		if (!Util::writeObjectToStream($result, $this->options['stream_output']))
			$this->logger->log(self::LOGGER_EVENT_ERROR, 'UNABLE TO WRITE TO STDOUT');
	}

	private function result_log(Result $result) {
		$data  = (array) $result->data();
		$buck  = $data['buck'];
		$event = $result->was_successful() ? Buck::LOGGER_EVENT_EXECUTE_COMPLETE : Buck::LOGGER_EVENT_DELEGATE_ERROR;
		unset($data['buck']);
		$buck->getLogger()->log($event, $data);
	}

	public function execute(Buck $buck) {

		$pid = $this->getLoggerId();
		$buck->getLogger()->log(Buck::LOGGER_EVENT_EXECUTE_START, $pid);

		$context = $buck->getContext();
		Buck::setThreadContext($pid, $context);

		ob_start();
		@trigger_error('');

		$this->data                = [];
		$this->data['pid']         = $pid;
		$this->data['buck']        = $buck;
		$this->data['runtime']     = -microtime(true);
		$this->data['memory_base'] = TRUMAN_BASE_MEMORY;

		ini_set('memory_limit',      $buck->getMemoryLimit());
		ini_set('max_execution_time', $buck->getTimeLimit());
		try {
			$this->data['retval'] = @$buck->invoke();
		} catch (Exception $ex) {
			$this->data['exception'] = $ex;
		}
		ini_set('memory_limit',       $this->original_memory_limit);
		ini_set('max_execution_time', $this->original_time_limit);

		$error = error_get_last();
		if (isset($error['message']{0}))
			$this->data['error'] = $error;
		if ($output = ob_get_clean())
			$this->data['output'] = $output;
		$this->data['runtime'] += microtime(true);
		$this->data['memory']   = Util::getMemoryUsage();

		Buck::unsetThreadContext($pid);

		$data   = (object) $this->data;
		$passed =
			!isset($data->exception) &&
			!isset($data->error)     && (
			!isset($data->retval)    ||
			(bool) $data->retval     );

		unset($this->data);
		gc_collect_cycles();

		$result = new Result($passed, $data);

		$this->result_log($result);

		return $result;

	}

}