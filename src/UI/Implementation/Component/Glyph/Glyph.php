<?php

/* Copyright (c) 2016 Richard Klees <richard.klees@concepts-and-training.de> Extended GPL, see docs/LICENSE */

namespace ILIAS\UI\Implementation\Component\Glyph;

use ILIAS\UI\Component as C;
use ILIAS\UI\Component\Counter\Counter;
use ILIAS\UI\Implementation\Component\ComponentHelper;

class Glyph implements C\Glyph\Glyph {
	use ComponentHelper;

	/**
	 * @var	string
	 */
	private $type;

	/**
	 * @var	C\Counter[]
	 */
	private $counters;

	private static $types = array
		( self::UP
		, self::DOWN
		, self::ADD
		, self::REMOVE
		, self::PREVIOUS
		, self::NEXT
		, self::CALENDAR
		, self::CLOSE
		, self::ATTACHMENT
		, self::CARET
		, self::DRAG
		, self::SEARCH
		, self::FILTER
		, self::INFO
		, self::ENVELOPE
		);


	/**
	 * @param string		$type
	 * @param C\Counter[]	$counters
	 */
	public function __construct($type) {
		$this->checkArgIsElement("type", $type, self::$types, "glyph type");
		$this->type = $type;
		$this->counters = array();
	}

	/**
	 * @inheritdoc
	 */
	public function getCounters() {
		return array_values($this->counters);
	}

	/**
	 * @inheritdoc
	 */
	public function withCounter(Counter $counter) {
		$clone = clone $this;
		$clone->counters[$counter->getType()] = $counter;
		return $clone;
	}

	/**
	 * @inheritdoc
	 */
	public function getType() {
		return $this->type;
	}

	/**
	 * @inheritdoc
	 */
	public function withType($type) {
		$this->checkArgIsElement("type", $type, self::$types, "glyph type");
		$clone = clone $this;
		$clone->type = $type;
		return $clone;
	}

	/**
	 * @return string
	 */
/*	public function to_html_string() {
		$type = '';
		switch (true) {
			case ($this->type instanceof E\DragGlyphType):
				$type = 'share-alt';
				break;
			case ($this->type instanceof E\EnvelopeGlyphType):
				$type = 'envelope';
				break;
		}
		$counter_html = '';
		if ($this->counters()) {
			foreach ($this->counters() as $counter) {
				$counter_html .= $counter->to_html_string();
			}
		}

		$tpl = new \ilTemplate('./src/UI/templates/default/Glyph/tpl.glyph.html', true, false);
		$tpl->setVariable('TYPE', $type);

		return $tpl->get() . $counter_html;
	}*/
}
