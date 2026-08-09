<?php

namespace Pablocarvalho\SqsTelemetry\Tests\Feature;

use Pablocarvalho\SqsTelemetry\Services\RequestSanitizer;
use Pablocarvalho\SqsTelemetry\Services\ServerRequestSnapshot;
use Pablocarvalho\SqsTelemetry\Services\TimelineContext;
use Pablocarvalho\SqsTelemetry\Tests\TestCase;

class ServerRequestSnapshotTest extends TestCase
{
    /**
     * @var array
     */
    protected $originalServer;

    /**
     * @var array
     */
    protected $originalGet;

    /**
     * @var array
     */
    protected $originalPost;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalServer = $_SERVER;
        $this->originalGet = $_GET;
        $this->originalPost = $_POST;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        $_GET = $this->originalGet;
        $_POST = $this->originalPost;

        parent::tearDown();
    }

    protected function snapshot(?TimelineContext $timeline = null): ServerRequestSnapshot
    {
        return new ServerRequestSnapshot(
            new RequestSanitizer(),
            $timeline ?: new TimelineContext()
        );
    }

    public function test_it_rebuilds_the_request_from_superglobals()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_HOST'] = 'universidade.test';
        $_SERVER['REQUEST_URI'] = '/wp/wp-admin/admin-ajax.php?action=heartbeat';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        $_SERVER['HTTP_USER_AGENT'] = 'LoadTest/1.0';
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true) - 0.25;

        $data = $this->snapshot()->capture('uc');

        $this->assertSame('uc', $data['project']);
        $this->assertSame('POST', $data['method']);
        $this->assertSame('http://universidade.test/wp/wp-admin/admin-ajax.php?action=heartbeat', $data['url']);
        $this->assertSame('203.0.113.10', $data['ip']);
        $this->assertSame('LoadTest/1.0', $data['user_agent']);
        $this->assertSame('shutdown', $data['capture_source']);
        $this->assertGreaterThan(200, $data['execution_time']);
    }

    public function test_it_redacts_credentials_the_same_way_the_middleware_does()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_HOST'] = 'universidade.test';
        $_SERVER['REQUEST_URI'] = '/wp/wp-login.php';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret123';
        $_SERVER['HTTP_COOKIE'] = 'wordpress_logged_in=abc';
        $_SERVER['HTTP_X_CUSTOM_HEADER'] = 'keep-me';
        $_POST = ['log' => 'admin', 'password' => 'hunter2'];

        $data = $this->snapshot()->capture('uc');

        $this->assertArrayNotHasKey('authorization', $data['headers']);
        $this->assertArrayNotHasKey('cookie', $data['headers']);
        $this->assertSame('keep-me', $data['headers']['x-custom-header']);
        $this->assertSame('admin', $data['payload']['log']);
        $this->assertSame('********', $data['payload']['password']);
    }

    public function test_it_carries_the_timeline_collected_before_the_request_was_handed_back()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';

        $timeline = new TimelineContext();
        $timeline->addEvent('db_query', 'select * from wp_posts', 4.2);

        $data = $this->snapshot($timeline)->capture('uc');

        $this->assertCount(1, $data['timeline']);
        $this->assertSame('db_query', $data['timeline'][0]['type']);
    }

    public function test_it_reports_https_from_the_forwarded_proto_header()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_HOST'] = 'universidade.test';
        $_SERVER['REQUEST_URI'] = '/wp-json/';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        $data = $this->snapshot()->capture('uc');

        $this->assertStringStartsWith('https://', $data['url']);
    }

    public function test_it_does_not_consider_a_console_run_an_http_request()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->assertFalse($this->webSnapshot('cli')->isHttpRequest());
    }

    public function test_it_recognises_a_web_request()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->assertTrue($this->webSnapshot('fpm-fcgi')->isHttpRequest());
    }

    public function test_a_web_sapi_without_a_request_method_is_not_a_request()
    {
        unset($_SERVER['REQUEST_METHOD']);

        $this->assertFalse($this->webSnapshot('fpm-fcgi')->isHttpRequest());
    }

    /**
     * Snapshot that reports the given SAPI, since the suite always runs on CLI.
     */
    protected function webSnapshot(string $sapi): ServerRequestSnapshot
    {
        return new class(new RequestSanitizer(), new TimelineContext(), $sapi) extends ServerRequestSnapshot
        {
            /** @var string */
            private $fakeSapi;

            public function __construct(RequestSanitizer $sanitizer, TimelineContext $timeline, string $sapi)
            {
                parent::__construct($sanitizer, $timeline);

                $this->fakeSapi = $sapi;
            }

            protected function sapi(): string
            {
                return $this->fakeSapi;
            }
        };
    }
}
