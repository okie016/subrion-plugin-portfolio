<?php
/******************************************************************************
 *
 * Subrion - open source content management system
 * Copyright (C) 2016 Intelliants, LLC <http://www.intelliants.com>
 *
 * This file is part of Subrion.
 *
 * Subrion is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Subrion is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Subrion. If not, see <http://www.gnu.org/licenses/>.
 *
 *
 * @link http://www.subrion.org/
 *
 ******************************************************************************/

class iaPortfolio extends abstractPlugin
{
	const ALIAS_SUFFIX = '.html';

	protected static $_table = 'portfolio_entries';
	protected $_tablePortfolioTags = 'portfolio_tags';
	protected $_tablePortfolioEntriesTags = 'portfolio_entries_tags';

	public $dashboardStatistics = true;


	public function getDashboardStatistics()
	{
		$statuses = array(iaCore::STATUS_ACTIVE, iaCore::STATUS_INACTIVE);
		$rows = $this->iaDb->keyvalue('`status`, COUNT(*)', '1 GROUP BY `status`', self::getTable());
		$total = 0;

		foreach ($statuses as $status)
		{
			isset($rows[$status]) || $rows[$status] = 0;
			$total += $rows[$status];
		}

		return array(
			'icon' => 'quill',
			'item' => iaLanguage::get('pf_entries'),
			'rows' => $rows,
			'total' => $total,
			'url' => 'portfolio/'
		);
	}

	public function titleAlias($title)
	{
		$result = iaSanitize::tags($title);

		$this->iaCore->factory('util');
		iaUtil::loadUTF8Functions('ascii', 'validation', 'bad', 'utf8_to_ascii');

		utf8_is_ascii($result) || $result = utf8_to_ascii($result);

		$result = preg_replace('#' . self::ALIAS_SUFFIX . '$#i', '', $result);
		$result = iaSanitize::alias($result);
		$result = substr($result, 0, 150); // the DB scheme applies this limitation
		$result .= self::ALIAS_SUFFIX;

		return $result;
	}

	public function delete($id)
	{
		$result = false;

		$this->iaDb->setTable(self::getTable());

		// if item exists, then remove it
		if ($row = $this->iaDb->row_bind(array('title', 'image'), '`id` = :id', array('id' => $id)))
		{
			$result[] = (bool)$this->iaDb->delete(iaDb::convertIds($id), self::getTable());

			if ($row['image'] && $result) // we have to remove the assigned image as well
			{
				$iaPicture = $this->iaCore->factory('picture');
				$iaPicture->delete($row['image']);
			}

			$result[] = (bool)$this->iaDb->delete(iaDb::convertIds($id, 'portfolio_id'), $this->_tablePortfolioEntriesTags);

			$sql =
				'DELETE ' .
				'FROM `:prefix:table_portfolio_tags` ' .
				'WHERE `id` NOT IN (' .
					'SELECT DISTINCT `tag_id` ' .
					'FROM `:prefix:table_portfolio_entries_tags`)';

			$sql = iaDb::printf($sql, array(
				'prefix' => $this->iaDb->prefix,
				'table_portfolio_entries_tags' => $this->_tablePortfolioEntriesTags,
				'table_portfolio_tags' => $this->_tablePortfolioTags
			));
			$result[] = (bool)$this->iaDb->query($sql);

			if ($result)
			{
				$this->iaCore->factory('log')->write(iaLog::ACTION_DELETE, array('module' => 'portfolio', 'item' => 'portfolio', 'name' => $row['title'], 'id' => (int)$id));
			}
		}

		$this->iaDb->resetTable();

		return $result;
	}

	public function getTags($id)
	{
		$sql =
			'SELECT GROUP_CONCAT(`title`) ' .
			'FROM `:prefix:table_portfolio_tags` pt ' .
			'WHERE `id` IN (' .
			'SELECT `tag_id` ' .
			'FROM `:prefix:table_portfolio_entries_tags` ' .
			'WHERE `portfolio_id` = :id)';

		$sql = iaDb::printf($sql, array(
			'prefix' => $this->iaDb->prefix,
			'table_portfolio_tags' => $this->_tablePortfolioTags,
			'table_portfolio_entries_tags' => $this->_tablePortfolioEntriesTags,
			'id' => $id
		));

		return $this->iaDb->getOne($sql);
	}

	public function getSitemapEntries()
	{
		$result = array();

		$stmt = '`status` = :status';
		$this->iaDb->bind($stmt, array('status' => iaCore::STATUS_ACTIVE));
		if ($rows = $this->iaDb->all(array('id', 'alias'), $stmt, null, null, self::getTable()))
		{
			foreach ($rows as $row)
			{
				$result[] = IA_URL . 'portfolio' . IA_URL_DELIMITER . $row['id'] . '-' . $row['alias'];
			}
		}

		return $result;
	}
}