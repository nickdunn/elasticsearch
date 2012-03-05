<?php

Class Drawer {
	
	public $drawer = NULL;
	public $button = NULL;
	
	public function __construct($title, $contents, $expanded=FALSE) {
		
		$handle = Lang::createHandle($title);
		
		$class = 'drawer ' . $handle;
		if($expanded) $class .= ' expanded';
		
		$drawer = new XMLElement('div');
		$drawer->setAttribute('class', $class);
		$drawer->setAttribute('id', 'drawer-' . $handle);
		
		$contents = new XMLElement('div', $contents, array('class' => 'contents'));
		$drawer->appendChild($contents);
		
		$button = new XMLElement('a', $title . ' <span class="arrow">&#8595;</span>', array('href' => '#drawer-' . $handle, 'class' => 'button' . ($expanded ? ' selected' : '')));
		
		$this->drawer = $drawer;
		$this->button = $button;
	}
	
}