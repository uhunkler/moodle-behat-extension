<?php

namespace Moodle\BehatExtension\Driver;

use Behat\Mink\Driver\Selenium2Driver as Selenium2Driver;

/**
 * Selenium2 driver extension to allow extra selenium capabilities restricted by behat/mink-extension.
 */
class MoodleSelenium2Driver extends Selenium2Driver
{

    /**
     * Dirty attribute to get the browser name; $browserName is private
     * @var string
     */
    protected static $browser;

    /**
     * Instantiates the driver.
     *
     * @param string    $browser Browser name
     * @param array     $desiredCapabilities The desired capabilities
     * @param string    $wdHost The WebDriver host
     * @param array     $moodleParameters Moodle parameters including our non-behat-friendly selenium capabilities
     */
    public function __construct($browserName = 'firefox', $desiredCapabilities = null, $wdHost = 'http://localhost:4444/wd/hub', $moodleParameters = false)
    {

        // If they are set add them overridding if it's the case (not likely).
        if (!empty($moodleParameters) && !empty($moodleParameters['capabilities'])) {
            foreach ($moodleParameters['capabilities'] as $key => $capability) {
                $desiredCapabilities[$key] = $capability;
            }
        }

        parent::__construct($browserName, $desiredCapabilities, $wdHost);

        // This class is instantiated by the dependencies injection system so
        // prior to all of beforeSuite subscribers which will call getBrowser*()
        self::$browser = $browserName;
    }

    /**
     * Forwards to getBrowser() so we keep compatibility with both static and non-static accesses.
     *
     * @deprecated
     * @param string $name
     * @param array $arguments
     * @return mixed
     */
    public static function __callStatic($name, $arguments)
    {
        if ($name == 'getBrowserName') {
            return self::getBrowser();
        }

        // Fallbacks calling the requested static method, we don't
        // even know if it exists or not.
        return call_user_func(array(self, $name), $arguments);
    }

    /**
     * Forwards to getBrowser() so we keep compatibility with both static and non-static accesses.
     *
     * @deprecated
     * @param string $name
     * @param array $arguments
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        if ($name == 'getBrowserName') {
            return self::getBrowser();
        }

        // Fallbacks calling the requested instance method, we don't
        // even know if it exists or not.
        return call_user_func(array($this, $name), $arguments);
    }

    /**
     * Returns the browser being used.
     *
     * We need to know it:
     * - To show info about the run.
     * - In case there are differences between browsers in the steps.
     *
     * @static
     * @return string
     */
    public static function getBrowser()
    {
        return self::$browser;
    }

    /**
     * Drag one element onto another.
     *
     * Override the original one to give YUI drag & drop
     * time to consider it a valid drag & drop. It will need
     * more changes in future to properly adapt to how YUI dd
     * component behaves.
     *
     * @param   string  $sourceXpath
     * @param   string  $destinationXpath
     */
    public function dragTo($sourceXpath, $destinationXpath)
    {
        $source      = $this->getWebDriverSession()->element('xpath', $sourceXpath);
        $destination = $this->getWebDriverSession()->element('xpath', $destinationXpath);

        // TODO: MDL-39727 This method requires improvements according to the YUI drag and drop component.

        $this->getWebDriverSession()->moveto(array(
            'element' => $source->getID()
        ));

        $script = <<<JS
(function (element) {
    var event = document.createEvent("HTMLEvents");

    event.initEvent("dragstart", true, true);
    event.dataTransfer = {};

    element.dispatchEvent(event);
}({{ELEMENT}}));
JS;
        $this->withSyn()->executeJsOnXpath($sourceXpath, $script);

        $this->getWebDriverSession()->buttondown();
        $this->getWebDriverSession()->moveto(array(
            'element' => $destination->getID()
        ));

        // We add a 2 seconds wait to make YUI dd happy.
        $this->wait(2 * 1000, false);

        $this->getWebDriverSession()->buttonup();

        $script = <<<JS
(function (element) {
    var event = document.createEvent("HTMLEvents");

    event.initEvent("drop", true, true);
    event.dataTransfer = {};

    element.dispatchEvent(event);
}({{ELEMENT}}));
JS;
        $this->withSyn()->executeJsOnXpath($destinationXpath, $script);
    }

    /**
     * Drag one element onto another with the Syn.drag() function.
     *
     * Add a new function for elements which use the Moodle
     * moodle-core-dragdrop-draghandle to use the Syn drag function
     * which works with YUI drag and drop.
     *
     * @param   string  $sourceXpath
     * @param   string  $destinationXpath
     */
    public function dragToWithHandle($sourceXpath, $destinationXpath)
    {
        // Get the id of the destination element - the drop target
        // either has an ID or has gotten a YUIid
        $toID = $this->getAttribute($destinationXpath, 'id');

        // Set the source (from) element via the executeJsOnXpath call.
        // Set destination (drop target) id with $toID.
        // Get the YUI nodes for the two elements and calculate the
        // position of both elemnts and the width/height of the drop target.
        // Set the parameters for the Syn.drag function with the
        // calculated values. Set the drop point to the middle width
        // and third height of the drop target. Get the DOM node from
        // the YUI node for the third parameter - the element which is draged.
        $script = <<<JS
(function (element) {
    var Yfrom = Y.one(element),
        YdragHandle = Yfrom.one('.moodle-core-dragdrop-draghandle'),
        Yto = Y.one('#{$toID}'),
        _toRegion = Yto.getAttribute('data-blockregion');

    // If the target region is a block region and is empty
    // and therefore hidden make the target region visible
    // before we can get the region position and size
    if (typeof _toRegion !== 'undefined' && Yto.getComputedStyle('display') === 'none') {
        Ybody = Y.one('body');
        if (Ybody.hasClass('empty-region-' + _toRegion)) {
            Ybody.removeClass('empty-region-' + _toRegion);
            Ybody.addClass('used-region-' + _toRegion);
        }
    }

    var _from = YdragHandle.getXY(),
        _to = Yto.getXY(),
        _toW = parseInt(Yto.getComputedStyle('width'), 10),
        _toH = parseInt(Yto.getComputedStyle('height'), 10);

    Syn.drag(
        {
            from: {pageX: _from[0] + 5, pageY: _from[1] + 5},
            to: {pageX: Math.floor(_to[0] + _toW / 2), pageY: Math.floor(_to[1] + _toH / 3)},
            duration: 500

        },
        Yfrom.getDOMNode()
    );
}({{ELEMENT}}));
JS;
        // Wait 2 seconds to give YUI time to initialize drag and drop
        $this->wait(2000, null);

        $this->withSyn()->executeJsOnXpath($sourceXpath, $script);

        // Wait 1 second to give Syn time to move the element and
        // YUI time to handle the drop
        $this->wait(1000, null);
    }

    /**
     * Overwriten method to use our custom Syn library.
     *
     * Makes sure that the Syn event library has been injected into the current page,
     * and return $this for a fluid interface,
     *
     *     $this->withSyn()->executeJsOnXpath($xpath, $script);
     *
     * @return Selenium2Driver
     */
    protected function withSyn()
    {
        $hasSyn = $this->getWebDriverSession()->execute(array(
            'script' => 'return typeof window["Syn"]!=="undefined"',
            'args'   => array()
        ));

        if (!$hasSyn) {
            $synJs = file_get_contents(__DIR__.'/Selenium2/moodle_syn-min.js');
            $this->getWebDriverSession()->execute(array(
                'script' => $synJs,
                'args'   => array()
            ));
        }

        return $this;
    }

    /**
     * Public interface to run Syn scripts.
     *
     * @see self::executeJsOnXpath()
     *
     * @param  string   $xpath  the xpath to search with
     * @param  string   $script the script to execute
     * @param  Boolean  $sync   whether to run the script synchronously (default is TRUE)
     *
     * @return mixed
     */
    public function triggerSynScript($xpath, $script, $sync = true)
    {
        return $this->withSyn()->executeJsOnXpath($xpath, $script, $sync);
    }

}
