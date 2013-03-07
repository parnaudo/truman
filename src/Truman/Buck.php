<?

class Truman_Buck {

	const PRIORITY_LOW     = 4096;
	const PRIORITY_MEDIUM  = 2048;
	const PRIORITY_HIGH    = 1024;
	const PRIORITY_URGENT  = 0;

	private $uuid;
	private $priority;
	private $callable;
	private $kwargs;
	protected $args = array();

	private static $_DEFAULT_OPTIONS = array(
		'priority' => self::PRIORITY_MEDIUM,
		'dedupe'   => false
	);

	public function __construct($callable, array $args = array(), array $options = array()) {

		$options += self::$_DEFAULT_OPTIONS;

		if (!is_callable($callable, true, $callable_name))
			Truman_Exception::throwNew($this, 'Invalid callable passed into '.__METHOD__);

		$this->callable = $callable_name;
		$this->priority = (int) $options['priority'];
		$this->uuid     = $this->calculateUUID($options['dedupe']);

		$this->args   = $args;
		$this->kwargs = (bool) array_filter(array_keys($args), 'is_string');

	}

	private function calculateUUID($dedupe = false) {
		$seed  = $this->callable;
		$seed .= implode(',', $this->args);
		$seed .= $dedupe ? '' : uniqid(microtime(1), true);
		return md5($seed);
	}

	public function getPriority() {
		return $this->priority;
	}

	public function getUUID() {
		return $this->uuid;
	}

	public function invoke() {

		try {

			if (strpos($this->callable, '::') !== false) {
				list($class, $method) = explode('::', $this->callable, 2);
				$function = new ReflectionMethod($class, $method);
			} else {
				$function = new ReflectionFunction($this->callable);
			}

			$args = array();

			if ($function->getNumberOfParameters() > 0) {
				if ($this->kwargs) {
					foreach ($function->getParameters() as $parameter) {
						$name     = $parameter->getName();
						$position = $parameter->getPosition();
						if (array_key_exists($name, $this->args))
							$args[$position] = $this->args[$name];
					}
				} else {
					$args = $this->args;
				}
			}

			if (isset($class))
				return $function->invokeArgs(null, $args);

			return $function->invokeArgs($args) ;

		} catch(ReflectionException $ex) {

			Truman_Exception::throwNew($this, "Unable to invoke '{$this->callable}'", $ex);

		}

	}

}