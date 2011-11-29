<?php

class ContentSectionTag extends Tag
{
	public function __toString()
	{
		return $this->handle();
	}

	public function handle()
	{
		return new StringTag($this->_val->handle);
	}

	public function label()
	{
		return new StringTag($this->_val->label);
	}

	public function urlFormat()
	{
		return new StringTag($this->_val->url_format);
	}

	public function template()
	{
		return new StringTag($this->_val->template);
	}

	public function maxPages()
	{
		return new NumTag($this->_val->max_pages);
	}

	public function parent()
	{
		return $this->_val->parent_id == null ? null : new ContentSectionTag($this->_val->parent_id);
	}

	public function hasSubSections()
	{
		$hasSubSections = Blocks::app()->content->doesSectionHaveSubSections($this->_val->id);
		return new BoolTag($hasSubSections);
	}

	public function site()
	{
		return new SiteTag($this->_val->site_id);
	}

	public function pages()
	{
		$pages = Blocks::app()->content->getPagesBySectionId($this->_val->id);
		return new ContentPagesTag($pages);
	}

	public function blocks()
	{
		$blocks = Blocks::app()->content->getBlocksBySectionId($this->_val->id);
		return new ContentBlocksTag($blocks);
	}
}
