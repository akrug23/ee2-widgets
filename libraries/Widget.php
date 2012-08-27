<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * @package 		PyroCMS
 * @subpackage 		Widget module
 * @author			Phil Sturgeon - PyroCMS Development Team
 *
 * Widget library takes care of the logic for widgets
 */
class Widget
{
	private $_widget = NULL;
	private $_rendered_areas = array();
	private $_widget_locations = array();

	function __construct()
	{
		$this->load->model('widgets_m');

		// Map where all widgets are
		foreach ($this->load->get_package_paths() as $path)
		{
			$widgets = glob($path.'widget/*', GLOB_ONLYDIR);

			if ( ! is_array($widgets))
			{
				$widgets = array();
			}

//			$module_widgets = glob($path.'modules/*/widgets/*', GLOB_ONLYDIR);
//
//			if ( ! is_array($widgets))
//			{
//				$module_widgets = array();
//			}
//
//			$widgets = array_merge($widgets, $module_widgets);

			foreach ($widgets as $widget_path)
			{
				$slug = basename($widget_path);

				// Set this so we know where it is later
				$this->_widget_locations[$slug] = $widget_path . '/';
			}
		}
	}

	function list_areas()
	{
		return $this->widgets_m->get_areas();
	}

	function list_area_instances($slug)
	{
		return $this->widgets_m->get_by_area($slug);
	}

	function list_available_widgets()
	{
		return $this->widgets_m->get_widgets();
	}

	function list_uninstalled_widgets()
	{
		$available = $this->list_available_widgets();
		$available_slugs = array();

		foreach ($available as $widget)
		{
			$available_slugs[] = $widget->slug;
		}

		$uninstalled = array();
		foreach ($this->_widget_locations as $widget_path)
		{
			$slug = basename($widget_path);

			if ( ! in_array($slug, $available_slugs) && $widget = $this->read_widget($slug))
			{
				$uninstalled[] = $widget;
			}
		}

		return $uninstalled;
	}

	function get_instance($instance_id)
	{
		$widget = $this->widgets_m->get_instance($instance_id);

		if ($widget)
		{
			$widget->options = $this->_unserialize_options($widget->options);
			return $widget;
		}

		return FALSE;
	}

	function get_area($id)
	{
		return is_numeric($id)
			? $this->widgets_m->get_area_by('id', $id)
			: $this->widgets_m->get_area_by('slug', $id);
	}

	function get_widget($id)
	{
		return is_numeric($id)
			? $this->widgets_m->get_widget_by('id', $id)
			: $this->widgets_m->get_widget_by('slug', $id);
	}


	function read_widget($slug)
	{
    	$this->_spawn_widget($slug);

		if ($this->_widget === FALSE)
		{
			return FALSE;
		}

		

    	$widget = (object) get_object_vars($this->_widget);
    	$widget->slug = $slug;
   
       	return $widget;
	}

    function render($name, $options = array())
    {
    	$this->_spawn_widget($name);

        $data = method_exists($this->_widget, 'run')
			? call_user_func(array($this->_widget, 'run'), $options)
			: array();

		// Don't run this widget
		if ($data === FALSE)
		{
			return FALSE;
		}

		// If we have TRUE, just make an empty array
		$data !== TRUE OR $data = array();

		// convert to array
		is_array($data) OR $data = (array) $data;

		$data['options'] = $options;

		return $this->load_view('display', $data);
    }

	function render_backend($name, $saved_data = array())
	{
		$this->_spawn_widget($name);

		// No fields, no backend, no rendering
		if (empty($this->_widget->fields))
		{
			return '';
		}

		$options = array();

		foreach ($this->_widget->fields as $field)
		{
			$field_name = &$field['field'];

			$options[$field_name] = set_value($field_name, @$saved_data[$field_name]);
		}

		// Check for default data if there is any
		$data = method_exists($this->_widget, 'form') ? call_user_func(array(&$this->_widget, 'form'), $options) : array();

		// Options we'rent changed, lets use the defaults
		isset($data['options']) OR $data['options'] = $options;

		return $this->load_view('form', $data);
	}

	function add_widget($input)
	{
		return $this->widgets_m->insert_widget($input);
	}

	function delete_widget($slug)
	{
		return $this->widgets_m->delete_widget($slug);
	}

	function add_area($input)
	{
		return $this->widgets_m->insert_area((array)$input);
	}

	function delete_area($id)
	{
		return $this->widgets_m->delete_area($id);
	}

	function add_instance($title, $widget_id, $widget_area_id, $options = array())
	{
		$slug = $this->get_widget($widget_id)->slug;

		if ( $error = $this->validation_errors($slug, $options) )
		{
			return array('status' => 'error', 'error' => $error);
		}

		// The widget has to do some stuff before it saves
		$options = $this->prepare_options($slug, $options);

		$this->widgets_m->insert_instance(array(
			'title' => $title,
			'widget_id' => $widget_id,
			'widget_area_id' => $widget_area_id,
			'options' => $this->_serialize_options($options)
		));

		return array('status' => 'success');
	}

	function edit_instance($instance_id, $title, $widget_area_id, $options = array())
	{
		$slug = $this->widgets_m->get_instance($instance_id)->slug;

		if ( $error = $this->validation_errors($slug, $options) )
		{
			return array('status' => 'error', 'error' => $error);
		}

		// The widget has to do some stuff before it saves
		$options = $this->widget->prepare_options($slug, $options);

		$this->widgets_m->update_instance($instance_id, array(
			'title' => $title,
			'widget_area_id' => $widget_area_id,
			'options' => $this->_serialize_options($options)
		));

		return array('status' => 'success');
	}

	function update_instance_order($id, $position)
	{
		return $this->widgets_m->update_instance_order($id, $position);
	}

	function delete_instance($id)
	{
		return $this->widgets_m->delete_instance($id);
	}

	function validation_errors($name, $options)
	{
		$this->_widget || $this->_spawn_widget($name);

	    if (property_exists($this->_widget, 'fields'))
    	{
    		$_POST = $options;

    		$this->load->library('form_validation');
    		$this->form_validation->set_rules($this->_widget->fields);

    		if (!$this->form_validation->run('', FALSE))
    		{
    			return validation_errors();
    		}
    	}
	}

    function prepare_options($name, $options = array())
    {
    	$this->_widget OR $this->_spawn_widget($name);

    	if (method_exists($this->_widget, 'save'))
	    {
			return (array) call_user_func(array(&$this->_widget, 'save'), $options);
	    }

	    return $options;
    }

    private function _spawn_widget($name)
    {
		$widget_path = $this->_widget_locations[$name];

		if (file_exists($widget_path . $name . EXT))
		{
			require_once $widget_path . $name . EXT;
			$class_name = 'Widget_'.ucfirst($name);

			$this->_widget = new $class_name;

			$this->_widget->path = $widget_path;

			return;
		}

		$this->_widget = NULL;
    }

    function __get($var)
    {
		return get_instance()->$var;
    }
    
    protected function load_view($view, $data = array())
	{
		$path = isset($this->_widget->path) ? $this->_widget->path : $this->path;

		$this->load->vars($data);

		return $this->load->file($path.'views/'.$view.'.php', TRUE);
	}

	private function _serialize_options($options)
	{
		return serialize((array) $options);
	}

	public function _unserialize_options($options)
	{
		return (array) unserialize($options);
	}
}
