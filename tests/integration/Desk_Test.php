<? require_once dirname(dirname(__DIR__)) . '/autoload.php';

use truman\core\Buck;
use truman\core\Desk;
use truman\core\Drawer;
use truman\core\Notification;
use truman\core\Socket;
use truman\core\Client;
use truman\core\Util;
use truman\test\integration\DeskCallbackAccumulator;

class Desk_Test extends PHPUnit_Framework_TestCase {

	public function testInclude() {
		$includes[] = Util::tempPhpFile('function a(){ return "a"; }');
		$includes[] = Util::tempPhpFile('function b(){ return "b"; }');
		$accumulator = new DeskCallbackAccumulator();
		$options[Desk::OPTION_INCLUDE] = $includes;
		$options = $accumulator->optionsExpectedResults(2, $options);
		$desk = new Desk(null, $options);
		$desk->enqueueBuck(new Buck('a'));
		$desk->enqueueBuck(new Buck('b'));
		$desk->start();
		$retvals = $accumulator->getResultRetvals();
		$this->assertContains('a', $retvals);
		$this->assertContains('b', $retvals);
		$desk->close();
	}

	public function testDeDupe() {
		$desk   = new Desk();
		$first  = $desk->enqueueBuck(new Buck('usleep', [100]));
		$second = $desk->enqueueBuck(new Buck('usleep', [100]));
		$this->assertNotNull($first);
		$this->assertNull($second);
		$this->assertEquals(1, $desk->getQueueSize());
		$desk->close();
	}

	public function testPriority() {
		$desk = new Desk();
		$low = $desk->enqueueBuck(new Buck('usleep', [100], [Buck::OPTION_PRIORITY => Buck::PRIORITY_LOW]));
		$medium = $desk->enqueueBuck(new Buck('usleep', [101], [Buck::OPTION_PRIORITY => Buck::PRIORITY_MEDIUM]));
		$high = $desk->enqueueBuck(new Buck('usleep', [102], [Buck::OPTION_PRIORITY => Buck::PRIORITY_HIGH]));
		$this->assertNotNull($low);
		$this->assertNotNull($medium);
		$this->assertNotNull($high);
		$this->assertEquals($high, $desk->processBuck());
		$this->assertEquals($medium, $desk->processBuck());
		$this->assertEquals($low, $desk->processBuck());
		$desk->close();
	}

	public function testRefresh() {

		$expected = 3;
		$includes[Desk::OPTION_DRAWER_COUNT] = $expected;
		$includes[Desk::OPTION_AUTO_REAP_DRAWERS] = false;
		$accumulator = new DeskCallbackAccumulator();
		$options = $accumulator->optionsExpectedResults(1, $includes);

		$desk = new Desk(null, $options);
		$old_keys = $desk->getDrawerKeys();
		$this->assertEquals($expected, $desk->getActiveDrawerCount());
		$desk->enqueueBuck(new Notification(Notification::TYPE_DESK_REFRESH));
		$desk->start();
		$this->assertEquals($expected, $desk->getActiveDrawerCount());
		$this->assertNotEquals($old_keys, $desk->getDrawerKeys());

		$desk->close();

	}

	public function testBuck() {
		$buck = new Buck('max', [1, 2]);
		$data = $this->resultAttributeTest($buck, 'buck', $buck);
		$this->assertEquals($buck, $data->buck);
	}

	public function testRetval() {
		$buck = new Buck('strlen', ['test']);
		$data = $this->resultAttributeTest($buck, 'retval', $buck->invoke());
		$this->assertEquals($buck->invoke(), $data->retval);
	}

	public function testException() {
		// reflectionException is a missing method, causing a Reflection Exception
		// this tests for how missing methods are handled, and how exceptions are returned
		$buck = new Buck('reflectionException', ['test', 'test']);
		$data = $this->resultAttributeTest($buck, 'exception');
		$this->assertInstanceOf('Exception', $data->exception);
	}

	public function testError() {
		$buck = new Buck('fopen');
		$data = $this->resultAttributeTest($buck, 'error');
		$this->assertEquals(E_WARNING, $data->error['type']);
	}

	public function testMemoryLimit() {
		$buck = new Buck('str_repeat', ['$', 1000000], [Buck::OPTION_MEMORY_LIMIT => 2048]);
		$data = $this->resultAttributeTest($buck, 'error');
		$this->assertEquals(E_ERROR, $data->error['type']);
	}

	public function testTimeLimit() {
		$buck = new Buck('sleep', [1], [Buck::OPTION_TIME_LIMIT => 1]);
		$data = $this->resultAttributeTest($buck, 'error');
		$this->assertEquals(E_USER_WARNING, $data->error['type']);
	}

	public function testOutput() {
		$buck = new Buck('passthru', ['hostname']);
		$data = $this->resultAttributeTest($buck, 'output');
		$this->assertContains(gethostname(), $data->output);
	}

	public function testBuckSocket() {
		$port   = 12345;
		$buck   = new Buck('strlen', ['test']);
		$accumulator = new DeskCallbackAccumulator();
		$desk = new Desk($port, $accumulator->optionsExpectedResults(1));
		$client = new Socket($port, [Socket::OPTION_FORCE_CLIENT_MODE => 1]);
		$this->assertTrue($client->send($buck));
		$desk->start();
		$this->assertInstanceOf('truman\core\Result', $result = $accumulator->getResultFirst());
		$this->assertInstanceOf('stdClass', $data = $result->getData());
		$this->assertObjectHasAttribute('retval', $data);
		$this->assertEquals($buck->invoke(), $data->retval);
		$desk->close();
	}

	// won't work as long as receiveResults receives multiple results
	public function disabled_testStartStop() {
		$accumulator = new DeskCallbackAccumulator();
		$desk = new Desk(null, $accumulator->optionsExpectedResults());
		$desk->enqueueBuck(new Buck('strlen', ['foo']));
		$desk->enqueueBuck(new Buck('strlen', ['test']));
		$this->assertEquals(1, $accumulator->getResultCount());
		$desk->close();
	}

	public function testCleanReap() {


		$includes = [Desk::OPTION_AUTO_REAP_DRAWERS => false];
		$accumulator = new DeskCallbackAccumulator();
		$options = $accumulator->optionsExpectedResults(1, $includes);

		$desk = new Desk(null, $options);
		$expected = $desk->getDrawerCount();

		$this->assertEquals($expected, $desk->getActiveDrawerCount());

		$killer = new Notification(Notification::TYPE_DRAWER_SIGNAL, 0);
		$desk->enqueueBuck($killer);
		$desk->start();
		usleep(50000); // give it some time to die...

		$this->assertNotEquals($expected, $desk->getActiveDrawerCount());
		$desk->reapDrawers();
		$this->assertEquals($expected, $desk->getActiveDrawerCount());

		$desk->close();

	}

	public function testDirtyReap() {

		$includes[Desk::OPTION_AUTO_REAP_DRAWERS] = false;
		$includes[Desk::OPTION_INCLUDE] = Util::tempPhpFile('function dirtyKill(){ exit(); }');
		$accumulator = new DeskCallbackAccumulator();
		$options = $accumulator->optionsExpectedResults(1, $includes);

		$desk = new Desk(null, $options);
		$expected = $desk->getDrawerCount();

		$this->assertEquals($expected, $desk->getActiveDrawerCount());

		$desk->enqueueBuck(new Buck('dirtyKill'));
		$desk->start();
		usleep(50000); // give it some time to die...

		$this->assertNotEquals($expected, $desk->getActiveDrawerCount());
		$desk->reapDrawers();
		$this->assertEquals($expected, $desk->getActiveDrawerCount());

		$desk->close();

	}

	private function resultAttributeTest(Buck $buck, $attribute) {
		$accumulator = new DeskCallbackAccumulator();
		$desk = new Desk(null, $accumulator->optionsExpectedResults(1));
		$desk->enqueueBuck($buck);
		$desk->start();
		$this->assertInstanceOf('truman\core\Result', $result = $accumulator->getResultFirst());
		$this->assertInstanceOf('stdClass', $data = $result->getData());
		$this->assertObjectHasAttribute($attribute, $data);
		$desk->close();
		return $data;
	}

}