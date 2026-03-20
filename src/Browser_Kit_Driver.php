<?php

declare (strict_types=1);
/*
 * This file is part of the Behat\Mink.
 * (c) Konstantin Kudryashov <ever.zet@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Behat\Mink\Driver;

use Behat\Mink\Exception\Driver_Exception;
use Behat\Mink\Exception\Unsupported_Driver_Action_Exception;
use Symfony\Component\Browser_Kit\Abstract_Browser;
use Symfony\Component\Browser_Kit\Cookie;
use Symfony\Component\Browser_Kit\Exception\BadMethodCallException;
use Symfony\Component\Browser_Kit\Response;
use Symfony\Component\Dom_Crawler\Crawler;
use Symfony\Component\Dom_Crawler\Field\Choice_Form_Field;
use Symfony\Component\Dom_Crawler\Field\File_Form_Field;
use Symfony\Component\Dom_Crawler\Field\Form_Field;
use Symfony\Component\Dom_Crawler\Field\Input_Form_Field;
use Symfony\Component\Dom_Crawler\Form;
use Symfony\Component\Http_Kernel\Http_Kernel_Browser;
/**
 * Symfony BrowserKit driver.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 *
 * @template TRequest of object
 * @template TResponse of object
 */
class Browser_Kit_Driver extends Core_Driver
{
    /**
     * @var AbstractBrowser<TRequest, TResponse>
     */
    private $client;
    /**
     * @var array<string, Form>
     */
    private $forms = [];
    /**
     * @var array<string, string>
     */
    private $server_parameters = [];
    /**
     * @var bool
     */
    private $started = false;
    /**
     * Initializes BrowserKit driver.
     *
     * @param AbstractBrowser<TRequest, TResponse> $client
     * @param string|null                          $baseUrl Base URL for HttpKernel clients
     */
    public function __construct(Abstract_Browser $client, ?string $base_url = null)
    {
        $this->client = $client;
        $this->client->follow_redirects(true);
        if ($base_url !== null && $client instanceof Http_Kernel_Browser) {
            $base_path = parse_url($base_url, PHP_URL_PATH);
            if (\is_string($base_path)) {
                $client->set_server_parameter('SCRIPT_FILENAME', $base_path);
            }
        }
    }
    /**
     * Returns BrowserKit browser instance.
     *
     * @return AbstractBrowser<TRequest, TResponse>
     */
    public function get_client()
    {
        return $this->client;
    }
    /**
     * {@inheritdoc}
     */
    public function start(): void
    {
        $this->started = true;
    }
    /**
     * {@inheritdoc}
     */
    public function is_started()
    {
        return $this->started;
    }
    /**
     * {@inheritdoc}
     */
    public function stop(): void
    {
        $this->reset();
        $this->started = false;
    }
    /**
     * {@inheritdoc}
     */
    public function reset(): void
    {
        // Restarting the client resets the cookies and the history
        $this->client->restart();
        $this->forms = [];
        $this->server_parameters = [];
    }
    /**
     * {@inheritdoc}
     */
    public function visit(string $url): void
    {
        $this->client->request('GET', $this->prepare_url($url), [], [], $this->server_parameters);
        $this->forms = [];
    }
    /**
     * {@inheritdoc}
     */
    public function get_current_url()
    {
        // This should be encapsulated in `getRequest` method if any other method needs the request
        try {
            $request = $this->client->get_internal_request();
        } catch (BadMethodCallException $e) {
            // Handling Symfony 5+ behaviour
            $request = null;
        }
        if ($request === null) {
            throw new Driver_Exception('Unable to access the request before visiting a page');
        }
        return $request->get_uri();
    }
    /**
     * {@inheritdoc}
     */
    public function reload(): void
    {
        $this->client->reload();
        $this->forms = [];
    }
    /**
     * {@inheritdoc}
     */
    public function forward(): void
    {
        $this->client->forward();
        $this->forms = [];
    }
    /**
     * {@inheritdoc}
     */
    public function back(): void
    {
        $this->client->back();
        $this->forms = [];
    }
    /**
     * {@inheritdoc}
     */
    public function set_basic_auth($user, string $password): void
    {
        if (false === $user) {
            unset($this->server_parameters['PHP_AUTH_USER'], $this->server_parameters['PHP_AUTH_PW']);
            unset($this->server_parameters['HTTP_AUTHORIZATION']);
            return;
        }
        $this->server_parameters['PHP_AUTH_USER'] = $user;
        $this->server_parameters['PHP_AUTH_PW'] = $password;
        $this->server_parameters['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode($user . ':' . $password);
    }
    /**
     * {@inheritdoc}
     */
    public function set_request_header(string $name, string $value): void
    {
        $content_headers = ['CONTENT_LENGTH' => true, 'CONTENT_MD5' => true, 'CONTENT_TYPE' => true];
        $name = str_replace('-', '_', strtoupper($name));
        // CONTENT_* are not prefixed with HTTP_ in PHP when building $_SERVER
        if (!isset($content_headers[$name])) {
            $name = 'HTTP_' . $name;
        }
        $this->server_parameters[$name] = $value;
    }
    /**
     * {@inheritdoc}
     */
    public function get_response_headers()
    {
        return $this->get_response()->get_headers();
    }
    /**
     * {@inheritdoc}
     */
    public function set_cookie(string $name, ?string $value = null): void
    {
        if (null === $value) {
            $this->delete_cookie($name);
            return;
        }
        $jar = $this->client->get_cookie_jar();
        $jar->set(new Cookie($name, $value));
    }
    /**
     * Deletes a cookie by name.
     *
     * @param string $name Cookie name.
     */
    private function delete_cookie(string $name): void
    {
        $path = $this->get_cookie_path();
        $jar = $this->client->get_cookie_jar();
        do {
            if (null !== $jar->get($name, $path)) {
                $jar->expire($name, $path);
            }
            $path = preg_replace('/.$/', '', $path);
        } while ($path);
    }
    /**
     * Returns current cookie path.
     */
    private function get_cookie_path(): string
    {
        $path = parse_url($this->get_current_url(), PHP_URL_PATH);
        if ($path === null || $path === false || $path === '') {
            $path = '/';
        }
        if ('\\' === DIRECTORY_SEPARATOR) {
            return str_replace('\\', '/', $path);
        }
        return $path;
    }
    /**
     * {@inheritdoc}
     */
    public function get_cookie(string $name)
    {
        // Note that the following doesn't work well because
        // Symfony\Component\BrowserKit\CookieJar stores cookies by name,
        // path, AND domain and if you don't fill them all in correctly then
        // you won't get the value that you're expecting.
        //
        // $jar = $this->client->getCookieJar();
        //
        // if (null !== $cookie = $jar->get($name)) {
        //     return $cookie->getValue();
        // }
        $all_values = $this->client->get_cookie_jar()->all_values($this->get_current_url());
        return $all_values[$name] ?? null;
    }
    /**
     * {@inheritdoc}
     */
    public function get_status_code()
    {
        $response = $this->get_response();
        return $response->get_status_code();
    }
    /**
     * {@inheritdoc}
     */
    public function get_content()
    {
        return $this->get_response()->get_content();
    }
    /**
     * {@inheritdoc}
     */
    public function find_element_xpaths(string $xpath)
    {
        $nodes = $this->get_crawler()->filter_x_path($xpath);
        $elements = [];
        foreach ($nodes as $i => $node) {
            $elements[] = sprintf('(%s)[%d]', $xpath, $i + 1);
        }
        return $elements;
    }
    /**
     * {@inheritdoc}
     */
    public function get_tag_name(string $xpath)
    {
        return $this->get_crawler_node($this->get_filtered_crawler($xpath))->node_name;
    }
    /**
     * {@inheritdoc}
     */
    public function get_text(string $xpath)
    {
        return str_replace(" ", ' ', $this->get_filtered_crawler($xpath)->text(null, true));
    }
    /**
     * {@inheritdoc}
     */
    public function get_html(string $xpath)
    {
        return $this->get_filtered_crawler($xpath)->html();
    }
    /**
     * {@inheritdoc}
     */
    public function get_outer_html(string $xpath)
    {
        $crawler = $this->get_filtered_crawler($xpath);
        return $crawler->outer_html();
    }
    /**
     * {@inheritdoc}
     */
    public function get_attribute(string $xpath, string $name)
    {
        $node = $this->get_filtered_crawler($xpath);
        if ($this->get_crawler_node($node)->has_attribute($name)) {
            return $node->attr($name);
        }
        return null;
    }
    /**
     * {@inheritdoc}
     */
    public function get_value(string $xpath)
    {
        if (in_array($this->get_attribute($xpath, 'type'), ['submit', 'image', 'button'], true)) {
            return $this->get_attribute($xpath, 'value');
        }
        $node = $this->get_crawler_node($this->get_filtered_crawler($xpath));
        if ('option' === $node->tag_name) {
            return $this->get_option_value($node);
        }
        try {
            $field = $this->get_form_field($xpath);
        } catch (\InvalidArgumentException $e) {
            return $this->get_attribute($xpath, 'value');
        }
        $value = $field->get_value();
        if ('select' === $node->tag_name && null === $value) {
            // symfony/dom-crawler returns null as value for a non-multiple select without
            // options but we want an empty string to match browsers.
            return '';
        }
        return $value;
    }
    /**
     * {@inheritdoc}
     */
    public function set_value(string $xpath, $value): void
    {
        $field = $this->get_form_field($xpath);
        if ($field instanceof Choice_Form_Field) {
            if (!\is_string($value) && $field->get_type() === 'radio') {
                throw new Driver_Exception('Only string values can be used for a radio input.');
            }
            if (!\is_bool($value) && $field->get_type() === 'checkbox') {
                throw new Driver_Exception('Only boolean values can be used for a checkbox input.');
            }
            if (\is_bool($value) && $field->get_type() === 'select') {
                throw new Driver_Exception('Boolean values cannot be used for a select element.');
            }
            $field->set_value($value);
            return;
        }
        if (\is_array($value) || \is_bool($value)) {
            throw new Driver_Exception('Textual and file form fields don\'t support array or boolean values.');
        }
        $field->set_value($value);
    }
    /**
     * {@inheritdoc}
     */
    public function check(string $xpath): void
    {
        $this->get_checkbox_field($xpath)->tick();
    }
    /**
     * {@inheritdoc}
     */
    public function uncheck(string $xpath): void
    {
        $this->get_checkbox_field($xpath)->untick();
    }
    /**
     * {@inheritdoc}
     */
    public function select_option(string $xpath, string $value, bool $multiple = false): void
    {
        $field = $this->get_form_field($xpath);
        if (!$field instanceof Choice_Form_Field) {
            throw new Driver_Exception(sprintf('Impossible to select an option on the element with XPath "%s" as it is not a select or radio input', $xpath));
        }
        if ($multiple) {
            $old_value = (array) $field->get_value();
            $old_value[] = $value;
            $value = $old_value;
        }
        $field->select($value);
    }
    /**
     * {@inheritdoc}
     */
    public function is_selected(string $xpath)
    {
        $option_value = $this->get_option_value($this->get_crawler_node($this->get_filtered_crawler($xpath)));
        $select_field = $this->get_form_field('(' . $xpath . ')/ancestor-or-self::*[local-name()="select"]');
        $select_value = $select_field->get_value();
        return is_array($select_value) ? in_array($option_value, $select_value, true) : $option_value === $select_value;
    }
    /**
     * {@inheritdoc}
     */
    public function click(string $xpath): void
    {
        $crawler = $this->get_filtered_crawler($xpath);
        $node = $this->get_crawler_node($crawler);
        $tag_name = $node->node_name;
        if ('a' === $tag_name) {
            $this->client->click($crawler->link());
            $this->forms = [];
        } elseif ($this->can_submit_form($node)) {
            $this->submit($crawler->form());
        } elseif ($this->can_reset_form($node)) {
            $this->reset_form($node);
        } else {
            $message = sprintf('%%s supports clicking on links and submit or reset buttons only. But "%s" provided', $tag_name);
            throw new Unsupported_Driver_Action_Exception($message, $this);
        }
    }
    /**
     * {@inheritdoc}
     */
    public function is_checked(string $xpath)
    {
        $field = $this->get_form_field($xpath);
        if (!$field instanceof Choice_Form_Field || 'select' === $field->get_type()) {
            throw new Driver_Exception(sprintf('Impossible to get the checked state of the element with XPath "%s" as it is not a checkbox or radio input', $xpath));
        }
        if ('checkbox' === $field->get_type()) {
            return $field->has_value();
        }
        $radio = $this->get_crawler_node($this->get_filtered_crawler($xpath));
        return $radio->get_attribute('value') === $field->get_value();
    }
    /**
     * {@inheritdoc}
     */
    public function attach_file(string $xpath, string $path): void
    {
        $field = $this->get_form_field($xpath);
        if (!$field instanceof File_Form_Field) {
            throw new Driver_Exception(sprintf('Impossible to attach a file on the element with XPath "%s" as it is not a file input', $xpath));
        }
        $field->upload($path);
    }
    /**
     * {@inheritdoc}
     */
    public function submit_form(string $xpath): void
    {
        $crawler = $this->get_filtered_crawler($xpath);
        $this->submit($crawler->form());
    }
    /**
     * @return Response
     *
     * @throws DriverException If there is not response yet
     */
    protected function get_response()
    {
        try {
            $response = $this->client->get_internal_response();
        } catch (BadMethodCallException $e) {
            // Handling Symfony 5+ behaviour
            $response = null;
        }
        if (null === $response) {
            throw new Driver_Exception('Unable to access the response before visiting a page');
        }
        return $response;
    }
    /**
     * Prepares URL for visiting.
     * Removes "*.php/" from urls and then passes it to BrowserKitDriver::visit().
     *
     *
     * @return string
     */
    protected function prepare_url(string $url)
    {
        return $url;
    }
    /**
     * Returns form field from XPath query.
     *
     *
     * @return FormField
     *
     * @throws DriverException
     * @throws \InvalidArgumentException when the field does not exist in the BrowserKit form
     */
    protected function get_form_field(string $xpath)
    {
        $field_node = $this->get_crawler_node($this->get_filtered_crawler($xpath));
        $field_type = $field_node->get_attribute('type');
        if (\in_array($field_type, ['button', 'submit', 'image'], true)) {
            throw new Driver_Exception(sprintf('Cannot access a form field of type "%s".', $field_type));
        }
        $field_name = str_replace('[]', '', $field_node->get_attribute('name'));
        $form_node = $this->get_form_node($field_node);
        $form_id = $this->get_form_node_id($form_node);
        if (!isset($this->forms[$form_id])) {
            $this->forms[$form_id] = new Form($form_node, $this->get_current_url());
        }
        if (is_array($this->forms[$form_id][$field_name])) {
            $position_field = $this->forms[$form_id][$field_name][$this->get_field_position($field_node)];
            \assert($position_field instanceof Form_Field);
            return $position_field;
        }
        return $this->forms[$form_id][$field_name];
    }
    /**
     * Returns the checkbox field from xpath query, ensuring it is valid.
     *
     *
     *
     * @throws DriverException when the field is not a checkbox
     */
    private function get_checkbox_field(string $xpath): Choice_Form_Field
    {
        $field = $this->get_form_field($xpath);
        if (!$field instanceof Choice_Form_Field) {
            throw new Driver_Exception(sprintf('Impossible to check the element with XPath "%s" as it is not a checkbox', $xpath));
        }
        return $field;
    }
    /**
     *
     *
     * @throws DriverException if the form node cannot be found
     */
    private function get_form_node(\Dom_Element $element): \Dom_Element
    {
        if ($element->has_attribute('form')) {
            $form_id = $element->get_attribute('form');
            \assert($element->owner_document !== null);
            $form_node = $element->owner_document->get_element_by_id($form_id);
            if (null === $form_node || 'form' !== $form_node->node_name) {
                throw new Driver_Exception(sprintf('The selected node has an invalid form attribute (%s).', $form_id));
            }
            return $form_node;
        }
        $form_node = $element;
        do {
            // use the ancestor form element
            if (null === $form_node = $form_node->parent_node) {
                throw new Driver_Exception('The selected node does not have a form ancestor.');
            }
        } while ('form' !== $form_node->node_name);
        \assert($form_node instanceof \Dom_Element);
        return $form_node;
    }
    /**
     * Gets the position of the field node among elements with the same name
     *
     * BrowserKit uses the field name as index to find the field in its Form object.
     * When multiple fields have the same name (checkboxes for instance), it will return
     * an array of elements in the order they appear in the DOM.
     *
     * @throws DriverException
     */
    private function get_field_position(\Dom_Element $field_node): int
    {
        $elements = $this->get_crawler()->filter_x_path('//*[@name=\'' . $field_node->get_attribute('name') . '\']');
        if (count($elements) > 1) {
            // more than one element contains this name !
            // so we need to find the position of $fieldNode
            foreach ($elements as $key => $element) {
                /** @var \DOMElement $element */
                if ($element->get_node_path() === $field_node->get_node_path()) {
                    return $key;
                }
            }
        }
        return 0;
    }
    private function submit(Form $form): void
    {
        $form_id = $this->get_form_node_id($form->get_form_node());
        if (isset($this->forms[$form_id])) {
            $this->merge_forms($form, $this->forms[$form_id]);
        }
        // remove empty file fields from request
        foreach ($form->get_files() as $name => $field) {
            if (empty($field['name']) && empty($field['tmp_name'])) {
                $form->remove($name);
            }
        }
        $this->client->submit($form, [], $this->server_parameters);
        $this->forms = [];
    }
    private function reset_form(\Dom_Element $field_node): void
    {
        $form_node = $this->get_form_node($field_node);
        $form_id = $this->get_form_node_id($form_node);
        unset($this->forms[$form_id]);
    }
    private function can_submit_form(\Dom_Element $node): bool
    {
        $type = $node->has_attribute('type') ? $node->get_attribute('type') : null;
        if ('input' === $node->node_name && in_array($type, ['submit', 'image'], true)) {
            return true;
        }
        return 'button' === $node->node_name && (null === $type || 'submit' === $type);
    }
    private function can_reset_form(\Dom_Element $node): bool
    {
        $type = $node->has_attribute('type') ? $node->get_attribute('type') : null;
        return in_array($node->node_name, ['input', 'button'], true) && 'reset' === $type;
    }
    /**
     * Returns form node unique identifier.
     *
     *
     */
    private function get_form_node_id(\Dom_Element $form): string
    {
        return md5($form->get_line_no() . $form->get_node_path() . $form->node_value);
    }
    /**
     * Gets the value of an option element
     *
     *
     *
     * @see \Symfony\Component\DomCrawler\Field\ChoiceFormField::buildOptionValue
     */
    private function get_option_value(\Dom_Element $option): string
    {
        if ($option->has_attribute('value')) {
            return $option->get_attribute('value');
        }
        if (!empty($option->node_value)) {
            return $option->node_value;
        }
        return '1';
        // DomCrawler uses 1 by default if there is no text in the option
    }
    /**
     * Merges second form values into first one.
     *
     * @param Form $to   merging target
     * @param Form $from merging source
     */
    private function merge_forms(Form $to, Form $from): void
    {
        foreach ($from->all() as $name => $field) {
            $field_reflection = new \Reflection_Object($field);
            $node_reflection = $field_reflection->get_property('node');
            $value_reflection = $field_reflection->get_property('value');
            if (PHP_VERSION_ID < 80100) {
                $node_reflection->set_accessible(true);
                $value_reflection->set_accessible(true);
            }
            $is_ignored_field = $field instanceof Input_Form_Field && in_array($node_reflection->get_value($field)->get_attribute('type'), ['submit', 'button', 'image'], true);
            if (!$is_ignored_field) {
                $target_field = $to[$name];
                \assert($target_field instanceof Form_Field);
                $value_reflection->set_value($target_field, $value_reflection->get_value($field));
            }
        }
    }
    /**
     * Returns DOMElement from crawler instance.
     *
     * @throws DriverException when the node does not exist
     */
    private function get_crawler_node(Crawler $crawler): \Dom_Element
    {
        $node = $crawler->get_node(0);
        if (null !== $node) {
            \assert($node instanceof \Dom_Element);
            return $node;
        }
        throw new Driver_Exception('The element does not exist');
    }
    /**
     * Returns a crawler filtered for the given XPath, requiring at least 1 result.
     *
     *
     *
     * @throws DriverException when no matching elements are found
     */
    private function get_filtered_crawler(string $xpath): Crawler
    {
        if (!count($crawler = $this->get_crawler()->filter_x_path($xpath))) {
            throw new Driver_Exception(sprintf('There is no element matching XPath "%s"', $xpath));
        }
        return $crawler;
    }
    /**
     * Returns crawler instance (got from client).
     *
     *
     * @throws DriverException
     */
    private function get_crawler(): Crawler
    {
        try {
            $crawler = $this->client->get_crawler();
        } catch (BadMethodCallException $e) {
            $crawler = null;
        }
        if (null === $crawler) {
            throw new Driver_Exception('Unable to access the response content before visiting a page');
        }
        return $crawler;
    }
}