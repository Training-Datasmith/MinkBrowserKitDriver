# Architecture: MinkBrowserKitDriver

## Purpose

Mink driver that uses Symfony's BrowserKit HTTP client as the browser backend. Suitable for functional tests that do not require JavaScript execution.

## Directory Structure

```
src/
  Browser_Kit_Driver.php   Single-file driver; implements Mink's Driver_Interface
tests/                     PHPUnit integration tests
```

## Key Design Decisions

- **Single-class driver**: The entire driver is one class that wraps `Symfony\Component\BrowserKit\AbstractBrowser`. This keeps the dependency surface small.
- **Request/Response lifecycle**: Each Mink navigation call translates to a BrowserKit `request()` call. The response DOM is parsed with `Symfony\Component\DomCrawler\Crawler`.
- **No JavaScript**: BrowserKit performs HTTP requests only; JavaScript interactions throw `UnsupportedDriverActionException`.

## Extension Points

- Pass any `AbstractBrowser` subclass (e.g., `KernelBrowser` for Symfony applications, `HttpBrowser` for real HTTP) to the constructor.

## Dependency Flow

```
Mink Session
  -> BrowserKitDriver::visit(url)
    -> AbstractBrowser::request(GET, url)
    -> DomCrawler parses response HTML
  -> BrowserKitDriver::find(xpath)
    -> Crawler::filterXPath(xpath)
```
