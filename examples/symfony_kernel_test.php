<?php

declare(strict_types=1);

/**
 * MinkBrowserKitDriver — Symfony functional test with BrowserKit example.
 *
 * Shows how to use the BrowserKit driver in PHPUnit + Mink tests for
 * Symfony applications without a real browser.
 *
 * --- PHPUnit test class ---
 *
 * use Behat\Mink\Mink;
 * use Behat\Mink\Session;
 * use Behat\MinkExtension\Driver\BrowserKitDriver;
 * use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
 *
 * class ProductPageTest extends WebTestCase
 * {
 *     private Mink $mink;
 *
 *     protected function setUp(): void
 *     {
 *         $client  = static::createClient();
 *         $driver  = new BrowserKitDriver($client);
 *         $session = new Session($driver);
 *
 *         $this->mink = new Mink(['default' => $session]);
 *         $this->mink->set_default_session_name('default');
 *         $this->mink->get_session()->start();
 *     }
 *
 *     protected function tearDown(): void
 *     {
 *         $this->mink->stop_sessions();
 *     }
 *
 *     public function testProductListPage(): void
 *     {
 *         $session = $this->mink->get_session();
 *         $session->visit('/products');
 *
 *         $this->assertEquals(200, $session->getStatusCode());
 *
 *         $page = $session->getPage();
 *         $this->assertNotNull($page->find('css', 'h1'));
 *         $this->assertStringContainsString('Products', $page->find('css', 'h1')->getText());
 *     }
 *
 *     public function testProductSearch(): void
 *     {
 *         $session = $this->mink->get_session();
 *         $session->visit('/products');
 *
 *         $page = $session->getPage();
 *         $page->fillField('search', 'Widget');
 *         $page->pressButton('Search');
 *
 *         $this->assertStringContainsString('/products?search=Widget', $session->getCurrentUrl());
 *     }
 * }
 */

echo 'MinkBrowserKitDriver requires a Symfony application and PHPUnit.' . PHP_EOL;
echo 'See the docblock above for test patterns.' . PHP_EOL;
