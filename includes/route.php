<?php
/**
 * @package    DIAFAN.CMS
 *
 * @author     diafan.ru
 * @version    6.0
 * @license    http://www.diafan.ru/license.html
 * @copyright  Copyright (c) 2003-2016 OOO «Диафан» (http://www.diafan.ru/)
 */

if (! defined('DIAFAN'))
{
	$path = __FILE__; $i = 0;
	while(! file_exists($path.'/includes/404.php'))
	{
		if($i == 10) exit; $i++;
		$path = dirname($path);
	}
	include $path.'/includes/404.php';
}

/**
 * Route
 * Набор функций для работы с ЧПУ
 */
class Route extends Diafan
{
	/**
	 * @var array переменные, передаваемые в URL в пользовательской части
	 */
	public $variable_names_site = array('cat', 'param', 'show', 'dpage', 'brand', 'year', 'month', 'day', 'step', 'sort', 'add', 'edit', 'page');

	/**
	 * @var array переменные, передаваемые в URL в административной части
	 */
	public $variable_names_admin = array ('edit', 'savenew', 'save', 'addnew', 'site', 'cat', 'parent', 'page', 'error', 'success');

	/**
	 * Подключает вспомогательные модули, если они не подключены
	 *
	 * @return boolean true
	 */
	public function __get($name)
	{
		if(IS_ADMIN)
		{
			$variable_names = $this->variable_names_admin;
		}
		else
		{
			$variable_names = $this->variable_names_site;
		}
		if (in_array($name, $variable_names))
		{
			if(! isset($this->cache["vars"][$name]))
			{
				$this->cache["vars"][$name] = '';
			}
			return $this->cache["vars"][$name];
		}
		return false;
	}

	/**
	 * Сохраняет ЧПУ
	 *
	 * @param string $rewrite псевдоссылка
	 * @param string $text значение, из которого автоматически генерируется ЧПУ
	 * @param integer $element_id номер элемента модуля
	 * @param string $module_name модуль
	 * @param string $element_type тип данных (*element* – элемент (по умолчанию), *cat* – категория, *param* – значение списка доп.характеристики, *brand* – производитель)
	 * @param integer $site_id номер страницы сайта
	 * @param integer $cat_id номер категории
	 * @param integer $parent_id номер родителя
	 * @param boolean $add_parents добавлять ЧПУ родительских элементов
	 * @param boolean $change_children изменить ЧПУ у детей
	 * @return void
	 */
	public function save($rewrite, $text, $element_id, $module_name, $element_type, $site_id, $cat_id = 0,  $parent_id = 0, $add_parents = false, $change_children = false)
	{
		$this->check_element_type($element_type);

		// если ЧПУ не задано и не нужно его генерировать, просто удаляем старое ЧПУ
		if (! $rewrite && ! ROUTE_AUTO_MODULE)
		{
			if($element_id == 1 && $module_name = 'site' && $element_type == 'element')
			{
				return;
			}
			$this->delete($element_id, $module_name, $element_type);
			return;
		}

		$row = DB::query_fetch_array("SELECT * FROM {rewrite} WHERE module_name='%s' AND element_id=%d AND element_type='%s'", $module_name, $element_id, $element_type);

		$rewrite_parent = '';

		if($site_id == 1 && $module_name == 'site' && ! $rewrite)
		{
			$rewrite = '';
		}
		else
		{
			// генерируем ЧПУ родительских элементов, если будем генерировать ЧПУ автоматически или это задано аргументом
			if($add_parents || ! $rewrite && ROUTE_AUTO_MODULE)
			{
				if($parent_id)
				{
					$tag_cache = $module_name."_".$element_type."_".$cat_id;
					if(! isset($this->cache["save_rewrite"][$tag_cache]))
					{
						$this->cache["save_rewrite"][$tag_cache] = DB::query_result("SELECT rewrite FROM {rewrite} WHERE module_name='%s' AND element_id=%d AND element_type='%s' LIMIT 1", $module_name, $parent_id, $element_type);
					}
					$rewrite_parent = $this->cache["save_rewrite"][$tag_cache];
				}
				if (! $rewrite_parent && $cat_id)
				{
					if(! isset($this->cache["save_rewrite"][$module_name."_cat_".$cat_id]))
					{
						$this->cache["save_rewrite"][$module_name."_cat_".$cat_id] = DB::query_result("SELECT rewrite FROM {rewrite} WHERE module_name='%s' AND element_id=%d AND element_type='cat' LIMIT 1", $module_name, $cat_id);
					}
					$rewrite_parent = $this->cache["save_rewrite"][$module_name."_cat_".$cat_id];
				}
				if (! $rewrite_parent && $module_name != 'site')
				{
					$rewrite_parent = DB::query_result("SELECT rewrite FROM {rewrite} WHERE module_name='site' AND element_id=%d AND element_type='element' LIMIT 1", $site_id);
				}
			}

			if(! $rewrite && ROUTE_AUTO_MODULE)
			{
				$rewrite = $this->generate_rewrite($text);
				if(! $rewrite)
				{
					$rewrite = $element_id;
				}
			}
			$rewrite = ($rewrite_parent ? $rewrite_parent.'/' : '').$rewrite;
		}

		// если ЧПУ уже есть в базе, добавляем в конце идентификатор текущей записи
		if (DB::query_result("SELECT COUNT(*) FROM {rewrite} WHERE rewrite='%h'".($row["id"] ? " AND id<>".$row["id"] : "")." AND trash='0'", $rewrite))
		{
			$rewrite .= $element_id;
		}

		if($row)
		{
			DB::query("UPDATE {rewrite} SET rewrite='%h' WHERE id=%d", $rewrite, $row["id"]);
			$id = $row["id"];
		}
		else
		{
			$id = DB::query("INSERT INTO {rewrite} (rewrite, module_name, element_id, element_type) VALUES ('%h', '%h', %d, '%s')", $rewrite, $module_name, $element_id, $element_type);
		}
		$this->cache["save_rewrite"][$module_name."_".$element_type."_".$element_id] = $rewrite;

		// обновляет ЧПУ у дочерних элементов
		if($change_children && $row)
		{
			
			$children_cat = $this->get_categories($module_name, $element_id);
			if($children_cat && isset($_POST['rewrite_sub']))
			{
				$chs = DB::query_fetch_all("SELECT * FROM {rewrite} WHERE module_name='%s' AND element_id IN (".implode(",", $children_cat).") AND element_type='%s' AND element_id <> %d", $module_name, $element_type, $element_id);
				foreach($chs as $ch)
				{
					if(strpos($ch["rewrite"], $row["rewrite"]) === 0)
					{
						$ch["rewrite"] = str_replace($row["rewrite"], $rewrite, $ch["rewrite"]);
						DB::query("UPDATE {rewrite} SET rewrite='%s' WHERE id=%d", $ch["rewrite"], $ch["id"]);
						$this->cache["save_rewrite"][$module_name."_".$element_type."_".$ch["element_id"]] = $ch["rewrite"];
					}
				}

				if ($element_type == 'cat') {
					$chs = DB::query_fetch_all("SELECT r.id, r.rewrite, r.element_id FROM {rewrite} AS r LEFT JOIN {".$module_name."} AS s ON r.element_id = s.id WHERE r.module_name='%s' AND s.cat_id IN (".implode(",", $children_cat).") AND r.element_type='%s'", $module_name, 'element');
					foreach($chs as $ch)
					{
						if(strpos($ch["rewrite"], $row["rewrite"]) === 0)
						{
							$ch["rewrite"] = str_replace($row["rewrite"], $rewrite, $ch["rewrite"]);
							DB::query("UPDATE {rewrite} SET rewrite='%s' WHERE id=%d", $ch["rewrite"], $ch["id"]);
							$this->cache["save_rewrite"][$module_name."_element_".$ch["element_id"]] = $ch["rewrite"];
						}
					}
				}
			}

			$children = $this->diafan->get_children($element_id, $module_name.(! $element_id && $cat_id ? '_category' : ''));
			if($children)
			{
				$chs = DB::query_fetch_all("SELECT * FROM {rewrite} WHERE module_name='%s' AND element_id IN (".implode(",", $children).") AND element_type='%s'", $module_name, $element_type);
				foreach($chs as $ch)
				{
					if(strpos($ch["rewrite"], $row["rewrite"]) === 0)
					{
						$ch["rewrite"] = str_replace($row["rewrite"], $rewrite, $ch["rewrite"]);
						DB::query("UPDATE {rewrite} SET rewrite='%s' WHERE id=%d", $ch["rewrite"], $ch["id"]);
						$this->cache["save_rewrite"][$module_name."_".$element_type."_".$ch["element_id"]] = $ch["rewrite"];
					}
				}
			}
		}
	}

	public function get_categories($module_name = '', $parent = 0)
	{
		$all_cats[] = $parent;
	    $children = DB::query_fetch_all("SELECT id FROM {".$module_name."_category} WHERE parent_id=%d", $parent);
	    if (!empty($children)) {
	    	foreach ($children as $key => $value) {
		        $current_id = $value['id'];
		       	$has_sub = DB::query_result("SELECT COUNT(id) FROM {".$module_name."_category} WHERE parent_id = %d", $current_id);
		       	if($has_sub > 0) {
		           	$all_cats[] = get_categories($current_id);
		        } else {
		        	$all_cats[] = $current_id;
		        }
	    	}
	    }
	    return $all_cats;
	}

	/**
	 * Удаляет ЧПУ одного или нескольких элементов
	 *
	 * @param integer|array $element_ids номер одного или нескольких элементов
	 * @param string $module_name название модуля
	 * @param string $element_type тип данных
	 * @return void
	 */
	public function delete($element_ids, $module_name, $element_type = 'element')
	{
		if(is_array($element_ids))
		{
			$where = " IN (%s)";
			$value = preg_replace('/[^0-9,]+/', '', implode(",", $element_ids));
		}
		else
		{
			$where = "=%d";
			$value = $element_ids;
		}
		DB::query("DELETE FROM {rewrite} WHERE module_name='%s' AND element_type='%s' AND element_id".$where, $module_name, $element_type, $value);
	}

	/**
	 * Генерирует псевдоссылку
	 *
	 * @param string $text исходный текст
	 * @return string
	 */
	public function generate_rewrite($text)
	{
		$rewrite = strip_tags($text);

		if(! isset($this->cache["route_method"]))
		{
			$this->cache["route_method"] = DB::query_result("SELECT value FROM {config} WHERE module_name='route' AND name='method' LIMIT 1");
		}
		switch ($this->cache["route_method"])
		{
			//перевод на английский
			case 2:
				if(! isset($this->cache["route_translate_yandex_key"]))
				{
					$this->cache["route_translate_yandex_key"] = DB::query_result("SELECT value FROM {config} WHERE module_name='route' AND name='translate_yandex_key' LIMIT 1");
				}
				$resp = file_get_contents('https://translate.yandex.net/api/v1.5/tr.json/translate?lang=ru-en&key='.$this->cache["route_translate_yandex_key"].'&text='.urlencode($rewrite));
				if (preg_match('/"text":\["([^"]+)"\]}/', $resp, $match))
				{
					$rewrite = trim($match[1]);
				}
				$rewrite = preg_replace('/[^A-Za-z0-9-_]+/', '', str_replace(' ', '-', $rewrite));
				//берутся первые 50 символов
				$rewrite = strtolower(substr($rewrite, 0, 50));
				break;

			//русская кириллица
			case 3:
				$rewrite = preg_replace('/[^A-Za-zабвгдеёжзийклмнопрстуфхцчшщъыьэюяАБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ0-9-_]+/', '', str_replace(' ', '-', $rewrite));
				//берутся первые 50 символов
				$rewrite = utf::strtolower(utf::substr($rewrite, 0, 50));
				break;

			//транскрипция
			default:
				if(! isset($this->cache["route_translit_array"]))
				{
					$this->cache["route_translit_array"] = DB::query_result("SELECT value FROM {config} WHERE module_name='route' AND name='translit_array' LIMIT 1");
				}
				if ($this->cache["route_translit_array"])
				{
					list( $ru, $eng ) = explode('````', $this->cache["route_translit_array"], 2);
					$ru_arr = explode('|', $ru);
					$eng_arr = explode('|', $eng);
					$rewrite = str_replace($ru_arr, $eng_arr, $rewrite);
				}
				else
				{
					$rewrite = $this->diafan->translit($rewrite);
				}
				$rewrite = preg_replace('/[^A-Za-z0-9-_]+/', '', $rewrite);
				//берутся первые 50 символов
				$rewrite = strtolower(substr($rewrite, 0, 50));
				break;
		}
		return $rewrite;
	}

	/**
	 * Генерирует ссылку
	 *
	 * @param integer $site_id номер страницы сайта
	 * @param integer $element_id номер элемента
	 * @param string $module_name модуль
	 * @param string $element_type тип данных (*element* – элемент (по умолчанию), *cat* – категория, *param* – значение списка доп.характеристики, *brand* – производитель)
	 * @param boolean $insert_route_end добавлять окончание ЧПУ в конце ссылки
	 * @return string
	 */
	public function link($site_id, $element_id = 0, $module_name = '', $element_type = 'element', $insert_route_end = true)
	{
		$this->check_element_type($element_type);

		if(! $element_id && ! $module_name)
		{
			$element_id = $site_id;
			$module_name = 'site';
			$site_id = 0;
		}
		if($element_id == 1 && $module_name == 'site' && $element_type == 'element')
		{
			return '';
		}
		if(! $element_id)
		{
			$link = $this->get_rewrite(0, $site_id, 'site', 'element');
			if($link)
			{
				$link .= '/';
			}
			return $link;
		}

		if ($link = $this->get_rewrite($site_id, $element_id, $module_name, $element_type))
		{
			$link .= ($insert_route_end ? ROUTE_END : 'ROUTE_END');
		}
		else
		{
			if(! $site_id && ! empty($this->cache["site_id"][$module_name.'_'.$element_type.'_'.$element_id]))
			{
				$site_id = $this->cache["site_id"][$module_name.'_'.$element_type.'_'.$element_id];
			}
			if($site_id)
			{
				$link = $this->get_rewrite(0, $site_id, 'site', 'element');
				if($link)
				{
					$link .= '/';
				}
				if($element_type == 'element')
				{
					$link .= 'show'.$element_id.'/';
				}
				else
				{
					$link .= $element_type.$element_id.'/';
				}
			}
		}

		return $link;
	}

	/**
	 * Подготавливает ЧПУ
	 *
	 * @param integer $site_id номер страницы сайта
	 * @param integer $element_id номер элемента
	 * @param string $module_name модуль
	 * @param string $element_type тип данных (*element* – элемент (по умолчанию), *cat* – категория, *param* – значение списка доп.характеристики, *brand* – производитель)
	 * @return void
	 */
	public function prepare($site_id, $element_id, $module_name, $element_type = 'element')
	{
		$this->check_element_type($element_type);
		if(isset($this->cache["rewrites"][$module_name.'_'.$element_type.'_'.$element_id]))
		{
			return;
		}
		if(! isset($this->cache["prepare"][$module_name][$element_type]) || ! in_array($element_id, $this->cache["prepare"][$module_name][$element_type]["element_id"]))
		{
			$this->cache["prepare"][$module_name][$element_type]["element_id"][] = $element_id;
			$this->cache["prepare"][$module_name][$element_type]["site_id"][] = $site_id;
		}
	}

	/**
	 * Получает ЧПУ страницы сайта по названию модуля
	 * 
	 * @param string $module_name название модуля
	 * @param boolean $route_end выводить окончание
	 * @return string|boolean false
	 */
	public function module($module_name, $route_end = true)
	{
		$key = "page_module_name".($route_end ? '_end' : '');
		if (! isset($this->cache[$key][$module_name]))
		{
			$site_id = DB::query_result(
				"SELECT s.id FROM {site} AS s"
				. ($this->diafan->configmodules('where_access_element', 'site') ? " LEFT JOIN {access} AS a ON a.element_id=s.id AND a.module_name='site' AND a.element_type='element'" : "")
				. " WHERE s.module_name='%s' AND s.trash='0' AND s.[act]='1'"
				.($this->diafan->configmodules('where_access_element', 'site') ? " AND (s.access='0' OR s.access='1' AND a.role_id=".$this->diafan->_users->role_id.")" : '')
				." LIMIT 1", $module_name
			);
			if(! $site_id)
			{
				$this->cache[$key][$module_name] = false;
			}
			else
			{
				$this->cache[$key][$module_name] = $this->link($site_id, 0, $route_end ? '' : $module_name);
			}
		}
		return $this->cache[$key][$module_name];
	}

	/**
	 * Определяет номер страницы, к которой прикреплен модуль, доступной текущему пользователю
	 * 
	 * @param string $module_name название модуля
	 * @param mixed $site_id номер страницы (если задан, определяет прикреплен ли модуль, есть ли доступ)
	 * @param boolean $return_array вернуть массив (или один номер)
	 * @return mixed
	 */
	public function id_module($module_name, $site_id = 0, $return_array = true)
	{
		if(empty($module_name))
			return false;

		// можно задать номер страницы в виде числа, массива чисел или строки чисел, разделенных запятой
		if($site_id)
		{
			if(! is_array($site_id))
			{
				$site_id = explode(",", $site_id);
			}
			$site_ids = array();
			foreach ($site_id as $s)
			{
				$s = intval($s);
				if($s)
				{
					$site_ids[] = $s;
				}
			}
		}
		// если задан номер страницы, то проверяем доступ и прикреплен ли модуль
		if(! empty($site_ids))
		{
			$new_site_id = array();
			$rows = DB::query_fetch_all("SELECT id, access FROM {site} WHERE id IN (%h) AND module_name='%h' AND [act]='1' AND trash='0'", implode(",", $site_id), $module_name);
			foreach ($rows as $row)
			{
				if($row["access"])
				{
					if (! $this->diafan->_users->role_id)
					{
						continue;
					}
			
					if($this->diafan->configmodules('where_access_element', 'site') &&
					   ! DB::query_result("SELECT role_id FROM {access} WHERE element_id=%d  AND module_name='site' AND element_type='element' AND role_id=%d LIMIT 1", $site_id, $this->diafan->_users->role_id))
						continue;
				}
				$new_site_id[] = $row["id"];
			}
			if($new_site_id)
			{
				// возвращаем номера страницы, к которым есть доступ и прикреплен модуль
				return $new_site_id;
			}
			else
			{
				return false;
			}
		}
		if (! isset($this->cache["page_module_name_id"][$module_name]))
		{
			$this->cache["page_module_name_id"][$module_name] = DB::query_fetch_value(
				"SELECT s.id FROM {site} AS s"
				.($this->diafan->configmodules('where_access_element', 'site') ? " LEFT JOIN {access} AS a ON a.element_id=s.id AND a.module_name='site' AND a.element_type='element'" : "")
				." WHERE s.module_name='%s' AND s.trash='0' AND s.[act]='1'"
				.($this->diafan->configmodules('where_access_element', 'site') ? " AND (s.access='0' OR s.access='1' AND a.role_id=".$this->diafan->_users->role_id.")" : ''),
				$module_name,
				"id"
			);
		}
		if(! empty($this->cache["page_module_name_id"][$module_name]))
		{
			if($return_array)
			{
				return $this->cache["page_module_name_id"][$module_name];
			}
			else
			{
				return $this->cache["page_module_name_id"][$module_name][0];
			}	
		}
		else
		{
			return false;
		}
	}

	/**
	 * Выдает URL текущей страницы с включенными или исключенными переменными
	 * 
	 * @param string|array $exclude исключенные переменные
	 * @param array $include включенные переменные
	 * @return string
	 */
	public function current_link($exclude = '', $include = array())
	{
		switch($this->diafan->_site->theme)
		{
			case '403.php':
			case '404.php':
			case '503.php':
			case 'm/403.php':
			case 'm/404.php':
			case 'm/503.php':
				return $this->diafan->_site->theme;
		}
		if (! is_array($exclude))
		{
			$exclude = array($exclude);
		}
		$args = array();
		$keys = array();
		foreach ($this->variable_names_site as $arg)
		{
			if (in_array($arg, array_keys($include)))
			{
				$args[] = array($arg => $include[$arg]);
				$keys[] = $arg;
			}
			elseif (! in_array($arg, $exclude))
			{
				if (! empty($this->$arg) && ($arg != "page" || $this->page != 1) && ($arg != "dpage" || $this->dpage != 1))
				{
					$args[] = array($arg => $this->$arg);
					$keys[] = $arg;
				}
			}
		}
		if (! $args)
		{
			$link = $this->link($this->diafan->_site->id);
		}
		elseif (in_array('show', $keys) && $this->get_rewrite($this->diafan->_site->id, $this->show, $this->diafan->_site->module))
		{
			foreach ($args as $i => $array)
			{
				if (! empty($array["show"]) || ! empty($array["cat"]))
				{
					unset($args[$i]);
				}
			}
			$link = $this->get_rewrite($this->diafan->_site->id, $this->show, $this->diafan->_site->module);
			if (! $args)
			{
				$link .= ROUTE_END;
			}
			else
			{
				$link .= '/';
			}
		}
		elseif (in_array('cat', $keys) && $this->get_rewrite($this->diafan->_site->id, $this->cat, $this->diafan->_site->module, "cat"))
		{
			foreach ($args as $i => $array)
			{
				if (! empty($array["cat"]))
				{
					unset($args[$i]);
				}
			}
			$link = $this->get_rewrite($this->diafan->_site->id, $this->cat, $this->diafan->_site->module, "cat");
			if (! $args)
			{
				$link .= ROUTE_END;
			}
			else
			{
				$link .= '/';
			}
		}
		elseif (in_array('brand', $keys) && $this->get_rewrite($this->diafan->_site->id, $this->brand, $this->diafan->_site->module, "brand"))
		{
			foreach ($args as $i => $array)
			{
				if (! empty($array["brand"]))
				{
					unset($args[$i]);
				}
			}
			$link = $this->get_rewrite($this->diafan->_site->id, $this->brand, $this->diafan->_site->module, "brand");
			if (! $args)
			{
				$link .= ROUTE_END;
			}
			else
			{
				$link .= '/';
			}
		}
		elseif (in_array('param', $keys) && $this->get_rewrite($this->diafan->_site->id, $this->param, $this->diafan->_site->module, "param"))
		{
			foreach ($args as $i => $array)
			{
				if (! empty($array["param"]))
				{
					unset($args[$i]);
				}
			}
			$link = $this->get_rewrite($this->diafan->_site->id, $this->param, $this->diafan->_site->module, "param");
			if (! $args)
			{
				$link .= ROUTE_END;
			}
			else
			{
				$link .= '/';
			}
		}
		else
		{
			$link = $this->get_rewrite(0, $this->diafan->_site->id, "site");
			$link .= ($link ? '/' : '');
		}
		foreach ($args as $array)
		{
			foreach ($array as $name => $value)
			{
				$link .= $name.$value.'/';
			}
		}
		return $link;
	}

	/**
	 * Выдает URL текущей страницы административной части с включенными переменными
	 * 
	 * @param string|array $exclude исключенные переменные
	 * @return string
	 */
	public function current_admin_link($exclude = '')
	{
		$key = serialize($exclude);
		if (! empty($this->cache[$key]))
		{
			return $this->cache[$key];
		}
		if (! is_array($exclude))
		{
			$exclude = array($exclude);
		}
		$args = array();
		foreach ($this->variable_names_admin as $arg)
		{
			if (! in_array($arg, $exclude))
			{
				if (! empty($this->$arg) && ($arg != "page" || $this->page != 1))
				{
					$args[] = array($arg => $this->$arg);
				}
			}
		}
		$link = BASE_PATH_HREF.($this->diafan->_admin->rewrite ? $this->diafan->_admin->rewrite.'/' : '');
		foreach ($args as $array)
		{
			foreach ($array as $name => $value)
			{
				$link .= $name.$value.'/';
			}
		}
		$this->cache[$key] = $link;
		return $link;
	}

	/**
	 * Ищет псевдоссылку в базе данных
	 *
	 * @param string $rewrite текущая псевдоссылка
	 * @param boolean $arguments_in_url в URL переданы аргументы
	 * @return array|boolean false
	 */
	public function search($rewrite, $arguments_in_url = false)
	{
		if (ROUTE_END != "/" && ! $arguments_in_url && $rewrite)
		{
			if (preg_match('/(.*)'.preg_quote(ROUTE_END, '/').'$/', $rewrite, $match))
			{
				$rewrite = $match[1];
			}
			else
			{
				return false;
			}
		}
		if ($row = DB::query_fetch_array("SELECT module_name, element_id, element_type, rewrite FROM {rewrite} WHERE trash='0' AND rewrite='%h' LIMIT 1", $rewrite))
		{
			return $row;
		}
		return false;
	}

	/**
	 * Заменяет ссылки на идентификаторы
	 *
	 * @param string $text исходный текст
	 * @return string
	 */
	public function replace_link_to_id($text)
	{
		if (!$text)
		{
			return $text;
		}
		if(preg_match_all('/href\=\"('.preg_quote(BASE_PATH, '/').'|\/)([^"]+)(\/'.(ROUTE_END != '/' ? '|'.preg_quote(ROUTE_END, '/') : '').')(\#[a-z0-9\_\-]+)*\"/', $text, $matches))
		{
			foreach ($matches[0] as $i => $m)
			{
				if(isset($this->cache["replace_link"][$matches[2][$i]]))
				{
					$replace = $this->cache["replace_link"][$matches[2][$i]];
				}
				else
				{
					$replace = '';
					$lang_id = $this->diafan->_languages->site;
					$anchor = substr($matches[4][$i], 1);
					foreach ($this->diafan->_languages->all as $lang)
					{
						if(preg_match('/^'.preg_quote($lang["shortname"], '/').'/', $matches[2][$i]))
						{
							$lang_id = $lang["id"];
							$matches[2][$i] = preg_match('/^'.preg_quote($lang["shortname"], '/').'\//', '', $matches[2][$i]);
						}
					}
					if($row = $this->diafan->_route->search($matches[2][$i]))
					{
						$replace = 'href="map:'
						.'lang_id='.$lang_id.';'
						.($row["module_name"] ? 'module_name='.$row["module_name"].';' : '')
						.($row["element_id"] ? 'element_id='.$row["element_id"].';' : '')
						.($row["element_type"] ? 'element_type='.$row["element_type"].';' : '')
						.($anchor ? 'anchor='.$anchor.';' : '')
						.'"';
					}
					$this->cache["replace_link"][$matches[2][$i]] = $replace;
				}
				if($replace)
				{
					$text = str_replace($m, $replace, $text);
				}
			}
		}
		$text = str_replace('src="'.BASE_PATH, 'src="BASE_PATH', $text);
		$text = str_replace('href="'.BASE_PATH, 'href="BASE_PATH', $text);
		return $text;
	}

	/**
	 * Заменяет идентификаторы ссылки на ЧПУ
	 *
	 * @param string $text исходный текст
	 * @return string
	 */
	public function replace_id_to_link($text)
	{
		if (! $text)
		{
			return $text;
		}
		if(preg_match_all('/href="map:([^"]+)"/', $text, $matches))
		{
			if(IS_ADMIN)
			{
				$path = BASE_PATH;
			}
			else
			{
				$path = BASE_PATH_HREF;
			}
			foreach ($matches[0] as $i => $m)
			{
				if(isset($this->cache["replace_id"][$matches[1][$i]]))
				{
					$replace = $this->cache["replace_id"][$matches[1][$i]];
				}
				else
				{
					$replace = '';
					$params = array(
							"lang_id" => 0,
							"module_name" => '',
							"element_id" => 0,
							"element_type" => '',
							"anchor" => '',
						);
					$params_ = explode(';', $matches[1][$i]);
					foreach ($params_ as $p)
					{
						if($p)
						{
							list($name, $value) = explode('=', $p);
							$params[$name] = $value;
						}
					}
					if($params["lang_id"] != $this->diafan->_languages->site)
					{
						foreach ($this->diafan->_languages->all as $lang)
						{
							if($lang["id"] == $params["lang_id"])
							{
								$replace .= $lang["shortname"].'/';
							}
						}
					}
					$replace .= $this->diafan->_route->link(0, $params["element_id"], $params["module_name"], $params["element_type"]).($params["anchor"] ? '#'.$params["anchor"] : '');
					$this->cache["replace_id"][$matches[1][$i]] = $replace;
				}
				$text = str_replace($m, 'href="'.$path.$replace.'"', $text);
			}
		}
		$text = str_replace('src="BASE_PATH', 'src="'.BASE_PATH, $text);
		$text = str_replace('href="BASE_PATH', 'href="'.BASE_PATH, $text);
		return $text;
	}

	/**
	 * Получает ЧПУ по тегу
	 *
	 * @param integer $site_id номер страницы сайта
	 * @param integer site_id номер элемента
	 * @param string $module_name модуль
	 * @param string $element_type тип данных (*element* – элемент (по умолчанию), *cat* – категория, *param* – значение списка доп.характеристики, *brand* – производитель)
	 * @return boolean true
	 */
	private function get_rewrite($site_id, $element_id, $module_name, $element_type = 'element')
	{
		$this->check_element_type($element_type);
		if(IS_ADMIN && $this->diafan->is_action("save"))
		{
			return DB::query_result("SELECT rewrite FROM {rewrite} WHERE trash='0' AND module_name='%h' AND element_id=%d AND element_type='%s' LIMIT 1", $module_name, $element_id, $element_type);
		}
		$this->prepare($site_id, $element_id, $module_name, $element_type);
		if(! empty($this->cache["prepare"]))
		{
			$where = array();
			foreach ($this->cache["prepare"] as $p_module_name => $array)
			{
				$w = array();
				foreach ($array as $p_element_type => $arr)
				{
					$w[] = "element_type='".$p_element_type."' AND element_id".(count($arr) > 1 ? " IN (".implode(",", $arr["element_id"]).")" : "=".implode(",", $arr["element_id"]));
					foreach ($arr["element_id"] as $p_element_id)
					{
						$this->cache["rewrites"][$p_module_name.'_'.$p_element_type.'_'.$p_element_id] = '';
					}
				}
				$where[] = "module_name='".$p_module_name."' AND ".(count($w) > 1 ? "(".implode(" OR ", $w).")" : $w[0]);
			}
			if(count($where) == 1 && count($array) == 1 && count($arr) == 1)
			{
				$where[0] .= " LIMIT 1";
			}
			$rows = DB::query_fetch_all("SELECT * FROM {rewrite} WHERE trash='0' AND ".(count($where) > 1 ? "(".implode(" OR ", $where).")" : $where[0]));
			foreach ($rows as $row)
			{
				$this->cache["rewrites"][$row["module_name"].'_'.$row["element_type"].'_'.$row["element_id"]] = $row["rewrite"];
			}

			$prepare_site_id = array();
			$prepare_rewrite_site_id = array();
			foreach ($this->cache["prepare"] as $p_module_name => $array)
			{
				foreach ($array as $p_element_type => $arr)
				{
					foreach($arr["element_id"] as $i => $p_element_id)
					{
						// если не найдены ЧПУ для элементов, но запоминаем ЧПУ их страницы сайта
						// если страница сайта не задана, то сначала ее определяем
						if($p_module_name != 'site' && empty($this->cache["rewrites"][$p_module_name.'_'.$p_element_type.'_'.$p_element_id]))
						{
							if(! empty($arr["site_id"][$i]))
							{
								if(! in_array($arr["site_id"][$i], $prepare_rewrite_site_id) && ! isset($this->cache["rewrites"]['site_element_'.$arr["site_id"][$i]]))
								{
									// страницы, для которых надо будет запомнить ЧПУ
									$prepare_rewrite_site_id[] = $arr["site_id"][$i];
								}
							}
							else
							{
								// элементы, для которых надо будет найти страницы
								$prepare_site_id[$p_module_name][$p_element_type][] = $p_element_id;
							}
						}
					}
				}
			}
			if(! empty($prepare_site_id))
			{
				foreach($prepare_site_id as $p_module_name => $arr)
				{
					foreach($arr as $p_element_type => $p_element_ids)
					{
						$table = '';
						switch($p_element_type)
						{
							case 'param':
								$p_site_id = $this->id_module($p_module_name, 0, false);
								break;

							case 'cat':
								$table = $p_module_name.'_category';
								break;
				
							case 'element':
								$table = $p_module_name;
								break;
				
							default:
								$table = $p_module_name.'_'.$p_element_type;
								break;
						}
						$p_site_id = 0;
						if(! empty($table))
						{
							$rows = DB::query_fetch_all("SELECT * FROM {%s} WHERE id IN (%s)", $table, implode(",", $p_element_ids));
							foreach($rows as $row)
							{
								if(! empty($row))
								{
									if(! empty($row["site_id"]))
									{
										$p_site_id = $row["site_id"];
									}
									else
									{
										$p_site_id = $this->id_module($p_module_name, 0, false);
									}
								}
							}
						}
						if($p_site_id)
						{
							$this->cache["site_id"][$p_module_name.'_'.$p_element_type.'_'.$row["id"]] = $p_site_id;
							if(! in_array($p_site_id, $prepare_rewrite_site_id) && ! isset($this->cache["rewrites"]['site_element_'.$p_site_id]))
							{
								// страницы, для которых надо будет запомнить ЧПУ
								$prepare_rewrite_site_id[] = $p_site_id;
							}
						}
					}
				}
			}
			if($prepare_rewrite_site_id)
			{
				$rows = DB::query_fetch_all("SELECT * FROM {rewrite} WHERE trash='0' AND module_name='site' AND element_type='element' AND element_id IN (%s)", implode(',', $prepare_rewrite_site_id));
				foreach ($rows as $row)
				{
					$this->cache["rewrites"][$row["module_name"].'_'.$row["element_type"].'_'.$row["element_id"]] = $row["rewrite"];
				}
			}
			unset($this->cache["prepare"]);
		}

		return $this->cache["rewrites"][$module_name.'_'.$element_type.'_'.$element_id];
	}
	
	/**
	 * Валидация типа элементов
	 *
	 * @return void
	 */
	private function check_element_type($element_type)
	{
		if(! in_array($element_type, array('element', 'cat', 'brand', 'param')))
		{
			throw new Exception($this->diafan->_('Некорректно задан тип элемента.'), E_NOTICE);
		}
	}
}