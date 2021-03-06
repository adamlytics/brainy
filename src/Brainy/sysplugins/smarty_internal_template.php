<?php
/**
 * Smarty Internal Plugin Template
 *
 * This file contains the Smarty template engine
 *
 * @package Brainy
 * @subpackage Template
 * @author Uwe Tews
 */

/**
 * Main class with template data structures and methods
 *
 * @package Brainy
 * @subpackage Template
 *
 * @property Smarty_Template_Source   $source
 * @property Smarty_Template_Compiled $compiled
 */
class Smarty_Internal_Template extends Smarty_Internal_TemplateBase
{
    /**
     * $compile_id
     * @var string
     */
    public $compile_id = null;
    /**
     * Template resource
     * @var string
     */
    public $template_resource = null;
    /**
     * flag if compiled template is invalid and must be (re)compiled
     * @var bool
     */
    public $mustCompile = null;
    /**
     * special compiled template properties
     * @var array
     */
    public $properties = array('file_dependency' => array(),
        'function' => array());
    /**
     * required plugins
     * @var array
     */
    public $required_plugins = array('compiled' => array());
    /**
     * Global smarty instance
     * @var Smarty
     */
    public $smarty = null;
    /**
     * blocks for template inheritance
     * @var array
     */
    public $block_data = array();
    /**
     * variable filters
     * @var array
     */
    public $variable_filters = array();
    /**
     * optional log of tag/attributes
     * @var array
     */
    public $used_tags = array();
    /**
     * internal flag to allow relative path in child template blocks
     * @var bool
     */
    public $allow_relative_path = false;
    /**
     * internal capture runtime stack
     * @var array
     */
    public $_capture_stack = array(0 => array());
    /**
     * Flag indicating that the template is running in strict mode
     * @var bool
     */
    public $strict_mode = false;

    /**
     * Create template data object
     *
     * Some of the global Smarty settings copied to template scope
     * It load the required template resources and cacher plugins
     *
     * @param string                   $template_resource template resource string
     * @param Smarty                   $smarty            Smarty instance
     * @param Smarty_Internal_Template $_parent           back pointer to parent object with variables or null
     * @param mixed                    $_compile_id       compile id or null
     */
    public function __construct($template_resource, $smarty, $_parent = null, $_compile_id = null) {
        $this->smarty = &$smarty;
        // Smarty parameter
        $this->compile_id = $_compile_id === null ? $this->smarty->compile_id : $_compile_id;
        $this->parent = $_parent;
        // Template resource
        $this->template_resource = $template_resource;
        // copy block data of template inheritance
        if ($this->parent instanceof Smarty_Internal_Template) {
            $this->block_data = $this->parent->block_data;
        }

        $this->smarty->fetchedTemplate($template_resource);
    }

    /**
     * Returns if the current template must be compiled by the Smarty compiler
     *
     * It does compare the timestamps of template source and the compiled templates and checks the force compile configuration
     *
     * @return boolean true if the template must be compiled
     */
    public function mustCompile() {
        if (!$this->source->exists) {
            if ($this->parent instanceof Smarty_Internal_Template) {
                $parent_resource = " in '$this->parent->template_resource}'";
            } else {
                $parent_resource = '';
            }
            throw new SmartyException("Unable to load template {$this->source->type} '{$this->source->name}'{$parent_resource}");
        }
        if ($this->mustCompile === null) {
            $this->mustCompile = (
                !$this->source->uncompiled &&
                ($this->smarty->force_compile ||
                    $this->source->recompiled ||
                    $this->compiled->timestamp === false ||
                    ($this->smarty->compile_check && $this->compiled->timestamp < $this->source->timestamp)
                    )
                );
        }

        return $this->mustCompile;
    }

    /**
     * Compiles the template
     *
     * If the template is not evaluated the compiled template is saved on disk
     */
    public function compileTemplateSource() {
        if (!$this->source->recompiled) {
            $this->properties['file_dependency'] = array();
            if ($this->source->components) {
                // for the extends resource the compiler will fill it
                // uses real resource for file dependency
                // $source = end($this->source->components);
                // $this->properties['file_dependency'][$this->source->uid] = array($this->source->filepath, $this->source->timestamp, $source->type);
            } else {
                $this->properties['file_dependency'][$this->source->uid] = array($this->source->filepath, $this->source->timestamp, $this->source->type);
            }
        }
        // compile locking
        if ($this->smarty->compile_locking && !$this->source->recompiled) {
            if ($saved_timestamp = $this->compiled->timestamp) {
                touch($this->compiled->filepath);
            }
        }
        // call compiler
        try {
            $code = $this->compiler->compileTemplate($this);
        } catch (Exception $e) {
            // restore old timestamp in case of error
            if ($this->smarty->compile_locking && !$this->source->recompiled && $saved_timestamp) {
                touch($this->compiled->filepath, $saved_timestamp);
            }
            throw $e;
        }
        // compiling succeded
        if (!$this->source->recompiled && $this->compiler->write_compiled_code) {
            // write compiled template
            $_filepath = $this->compiled->filepath;
            if ($_filepath === false)
                throw new SmartyException('getCompiledFilepath() did not return a destination to save the compiled template to');
            Smarty_Internal_Write_File::writeFile($_filepath, $code, $this->smarty);
            $this->compiled->exists = true;
            $this->compiled->isCompiled = true;
        }
        // release compiler object to free memory
        unset($this->compiler);
    }

    /**
     * Template code runtime function to get subtemplate content
     *
     * @param string  $template       the resource handle of the template file
     * @param mixed   $compile_id     compile id to be used with this template
     * @param array   $vars           optional  variables to assign
     * @param int     $parent_scope   scope in which {include} should execute
     * @return string template content
     */
    public function getSubTemplate($template, $compile_id, $data, $parent_scope) {
        // already in template cache?
        if ($this->smarty->allow_ambiguous_resources) {
            $_templateId = Smarty_Resource::getUniqueTemplateName($this, $template) . $compile_id;
        } else {
            $_templateId = $this->smarty->joined_template_dir . '#' . $template . $compile_id;
        }

        if (isset($_templateId[150])) {
            $_templateId = sha1($_templateId);
        }
        if (isset($this->smarty->template_objects[$_templateId])) {
            // clone cached template object because of possible recursive call
            $tpl = clone $this->smarty->template_objects[$_templateId];
            $tpl->parent = $this;
        } else {
            $tpl = new $this->smarty->template_class($template, $this->smarty, $this, $compile_id);
        }
        // get variables from calling scope
        if ($parent_scope == Smarty::SCOPE_LOCAL) {
            $tpl->tpl_vars = $this->tpl_vars;
            $tpl->tpl_vars['smarty'] = clone $this->tpl_vars['smarty'];
        } elseif ($parent_scope == Smarty::SCOPE_PARENT) {
            $tpl->tpl_vars = &$this->tpl_vars;
        } elseif ($parent_scope == Smarty::SCOPE_GLOBAL) {
            $tpl->tpl_vars = &Smarty::$global_tpl_vars;
        } elseif (($scope_ptr = $this->getScopePointer($parent_scope)) == null) {
            $tpl->tpl_vars = &$this->tpl_vars;
        } else {
            $tpl->tpl_vars = &$scope_ptr->tpl_vars;
        }
        $tpl->config_vars = $this->config_vars;
        if ($data) {
            // set up variable values
            foreach ($data as $_key => $_val) {
                $tpl->tpl_vars[$_key] = new Smarty_variable($_val);
            }
        }

        return $tpl->fetch(null, null, null, null, false, false, true);
    }

    /**
     * Template code runtime function to set up an inline subtemplate
     *
     * @param string  $template       the resource handle of the template file
     * @param mixed   $compile_id     compile id to be used with this template
     * @param array   $vars           optional  variables to assign
     * @param int     $parent_scope   scope in which {include} should execute
     * @returns string template content
     */
    public function setupInlineSubTemplate($template, $compile_id, $data, $parent_scope) {
        $tpl = new $this->smarty->template_class($template, $this->smarty, $this, $compile_id);
        // get variables from calling scope
        if ($parent_scope == Smarty::SCOPE_LOCAL) {
            $tpl->tpl_vars = $this->tpl_vars;
            $tpl->tpl_vars['smarty'] = clone $this->tpl_vars['smarty'];
        } elseif ($parent_scope == Smarty::SCOPE_PARENT) {
            $tpl->tpl_vars = &$this->tpl_vars;
        } elseif ($parent_scope == Smarty::SCOPE_GLOBAL) {
            $tpl->tpl_vars = &Smarty::$global_tpl_vars;
        } elseif (($scope_ptr = $this->getScopePointer($parent_scope)) == null) {
            $tpl->tpl_vars = &$this->tpl_vars;
        } else {
            $tpl->tpl_vars = &$scope_ptr->tpl_vars;
        }
        $tpl->config_vars = $this->config_vars;
        if (!empty($data)) {
            // set up variable values
            foreach ($data as $_key => $_val) {
                $tpl->tpl_vars[$_key] = new Smarty_variable($_val);
            }
        }

        return $tpl;
    }


    /**
     * Create code frame for compiled and cached templates
     *
     * @param  string $content optional template content
     * @param  bool   $cache   flag for cache file
     * @return string
     */
    public function createTemplateCodeFrame($content = '', $cache = false) {
        $plugins_string = '';
        // include code for plugins
        if (!empty($this->required_plugins['compiled'])) {
            foreach ($this->required_plugins['compiled'] as $tmp) {
                foreach ($tmp as $data) {
                    $file = addslashes($data['file']);
                    if (is_Array($data['function'])) {
                        $plugins_string .= "if (!is_callable(array('{$data['function'][0]}','{$data['function'][1]}'))) include '{$file}';\n";
                    } else {
                        $plugins_string .= "if (!is_callable('{$data['function']}')) include '{$file}';\n";
                    }
                }
            }
        }
        // build property code
        $output = '';
        if (!$this->source->recompiled) {
            $output = "/*%%SmartyHeaderCode%%*/";
            $output .= "if(!defined('SMARTY_DIR')) exit('no direct access allowed');\n";
        }
        if ($cache) {
            // remove compiled code of{function} definition
            unset($this->properties['function']);
        }
        $this->properties['version'] = Smarty::SMARTY_VERSION;
        if (!isset($this->properties['unifunc'])) {
            $this->properties['unifunc'] = 'content_' . str_replace(array('.',','), '_', uniqid('', true));
        }
        if (!$this->source->recompiled) {
            $output .= "\$_valid = \$_smarty_tpl->decodeProperties(" . var_export($this->properties, true) . ',' . ($cache ? 'true' : 'false') . "); /*/%%SmartyHeaderCode%%*/\n";
            $output .= 'if ($_valid && !is_callable(\'' . $this->properties['unifunc'] . '\')) {';

            // Output a proper PHPDoc for Augmented Types users.
            $output .= <<<'PHPDOC'
/**
 * @param Smarty_Internal_TemplateBase $_smarty_tpl The smarty template instance
 * @return void
 */
PHPDOC;

            $output .= 'function ' . $this->properties['unifunc'] . "(\$_smarty_tpl) {\n";
        }
        $output .= $plugins_string;
        $output .= $content;
        if (!$this->source->recompiled) {
            $output .= "}\n}\n";
        }

        return $output;
    }

    /**
     * This function is executed automatically when a compiled or cached template file is included
     *
     * - Decode saved properties from compiled template and cache files
     * - Check if compiled or cache file is valid
     *
     * @param  array $properties special template properties
     * @param  bool  $cache      flag if called from cache file
     * @return bool  flag if compiled or cache file is valid
     */
    public function decodeProperties($properties, $cache = false) {
        if (isset($properties['file_dependency'])) {
            $this->properties['file_dependency'] = array_merge($this->properties['file_dependency'], $properties['file_dependency']);
        }
        if (!empty($properties['function'])) {
            $this->properties['function'] = array_merge($this->properties['function'], $properties['function']);
            $this->smarty->template_functions = array_merge($this->smarty->template_functions, $properties['function']);
        }
        $this->properties['version'] = (isset($properties['version'])) ? $properties['version'] : '';
        $this->properties['unifunc'] = $properties['unifunc'];
        // check file dependencies at compiled code
        $is_valid = true;
        if ($this->properties['version'] != Smarty::SMARTY_VERSION) {
            $is_valid = false;
        } elseif (((!$cache && $this->smarty->compile_check && empty($this->compiled->_properties) && !$this->compiled->isCompiled) || $cache && ($this->smarty->compile_check === true || $this->smarty->compile_check === Smarty::COMPILECHECK_ON)) && !empty($this->properties['file_dependency'])) {
            foreach ($this->properties['file_dependency'] as $_file_to_check) {
                if ($_file_to_check[2] == 'file' || $_file_to_check[2] == 'php') {
                    if ($this->source->filepath == $_file_to_check[0] && isset($this->source->timestamp)) {
                        // do not recheck current template
                        $mtime = $this->source->timestamp;
                    } else {
                        // file and php types can be checked without loading the respective resource handlers
                        $mtime = @filemtime($_file_to_check[0]);
                    }
                } elseif ($_file_to_check[2] == 'string') {
                    continue;
                } else {
                    $source = Smarty_Resource::source(null, $this->smarty, $_file_to_check[0]);
                    $mtime = $source->timestamp;
                }
                if (!$mtime || $mtime > $_file_to_check[1]) {
                    $is_valid = false;
                    break;
                }
            }
        }
        $this->mustCompile = !$is_valid;
        // store data in reusable Smarty_Template_Compiled
        $this->compiled->_properties = $properties;

        return $is_valid;
    }

    /**
     * Template code runtime function to create a local Smarty variable for array assignments
     *
     * @param string $tpl_var tempate variable name
     * @param int    $scope   scope of variable
     */
    public function createLocalArrayVariable($tpl_var, $scope = Smarty::SCOPE_LOCAL) {
        if (!isset($this->tpl_vars[$tpl_var])) {
            $this->tpl_vars[$tpl_var] = new Smarty_variable(array(), $scope);
        } else {
            $this->tpl_vars[$tpl_var] = clone $this->tpl_vars[$tpl_var];
            if ($scope != Smarty::SCOPE_LOCAL) {
                $this->tpl_vars[$tpl_var]->scope = $scope;
            }
            if (!(is_array($this->tpl_vars[$tpl_var]->value) || $this->tpl_vars[$tpl_var]->value instanceof ArrayAccess)) {
                settype($this->tpl_vars[$tpl_var]->value, 'array');
            }
        }
    }

    /**
     * Template code runtime function to get pointer to template variable array of requested scope
     *
     * @param  int   $scope requested variable scope
     * @return array array of template variables
     */
    public function &getScope($scope) {
        if ($scope == Smarty::SCOPE_PARENT && !empty($this->parent)) {
            return $this->parent->tpl_vars;
        } elseif ($scope == Smarty::SCOPE_ROOT && !empty($this->parent)) {
            $ptr = $this->parent;
            while (!empty($ptr->parent)) {
                $ptr = $ptr->parent;
            }

            return $ptr->tpl_vars;
        } elseif ($scope == Smarty::SCOPE_GLOBAL) {
            return Smarty::$global_tpl_vars;
        }
        $null = null;

        return $null;
    }

    /**
     * Get parent or root of template parent chain
     *
     * @param  int   $scope pqrent or root scope
     * @return mixed object
     */
    public function getScopePointer($scope) {
        if ($scope == Smarty::SCOPE_PARENT && !empty($this->parent)) {
            return $this->parent;
        } elseif ($scope == Smarty::SCOPE_ROOT && !empty($this->parent)) {
            $ptr = $this->parent;
            while (!empty($ptr->parent)) {
                $ptr = $ptr->parent;
            }

            return $ptr;
        }

        return null;
    }

    /**
     * [util function] counts an array, arrayaccess/traversable or PDOStatement object
     *
     * @param  mixed $value
     * @return int   the count for arrays and objects that implement countable, 1 for other objects that don't, and 0 for empty elements
     */
    public function _count($value) {
        if (is_array($value) === true || $value instanceof Countable) {
            return count($value);
        } elseif ($value instanceof IteratorAggregate) {
            // Note: getIterator() returns a Traversable, not an Iterator
            // thus rewind() and valid() methods may not be present
            return iterator_count($value->getIterator());
        } elseif ($value instanceof Iterator) {
            return iterator_count($value);
        } elseif ($value instanceof PDOStatement) {
            return $value->rowCount();
        } elseif ($value instanceof Traversable) {
            return iterator_count($value);
        } elseif ($value instanceof ArrayAccess) {
            if ($value->offsetExists(0)) {
                return 1;
            }
        } elseif (is_object($value)) {
            return count($value);
        }

        return 0;
    }

    /**
     * runtime error not matching capture tags
     *
     */
    public function capture_error() {
        throw new SmartyException("Not matching {capture} open/close in \"{$this->template_resource}\"");
    }

    /**
    * Empty cache for this template
    *
    * @param integer $exp_time      expiration time
    * @return integer number of cache files deleted
    * @deprecated no-op
    */
    public function clearCache($exp_time=null) {
        return 0;
    }

     /**
     * set Smarty property in template context
     *
     * @param string $property_name property name
     * @param mixed  $value         value
     */
    public function __set($property_name, $value) {
        switch ($property_name) {
            case 'source':
            case 'compiled':
            case 'compiler':
                $this->$property_name = $value;

                return;

            // FIXME: routing of template -> smarty attributes
            default:
                if (property_exists($this->smarty, $property_name)) {
                    $this->smarty->$property_name = $value;

                    return;
                }
        }

        throw new SmartyException("invalid template property '$property_name'.");
    }

    /**
     * get Smarty property in template context
     *
     * @param string $property_name property name
     */
    public function __get($property_name) {
        switch ($property_name) {
            case 'source':
                if (strlen($this->template_resource) == 0) {
                    throw new SmartyException('Missing template name');
                }
                $this->source = Smarty_Resource::source($this);
                // cache template object under a unique ID
                // do not cache eval resources
                if ($this->source->type != 'eval') {
                    if ($this->smarty->allow_ambiguous_resources) {
                        $_templateId = $this->source->unique_resource . $this->compile_id;
                    } else {
                        $_templateId = $this->smarty->joined_template_dir . '#' . $this->template_resource . $this->compile_id;
                    }

                    if (isset($_templateId[150])) {
                        $_templateId = sha1($_templateId);
                    }
                    $this->smarty->template_objects[$_templateId] = $this;
                }

                return $this->source;

            case 'compiled':
                $this->compiled = $this->source->getCompiled($this);

                return $this->compiled;

            case 'compiler':
                $this->smarty->loadPlugin($this->source->compiler_class);
                $this->compiler = new $this->source->compiler_class($this->source->template_lexer_class, $this->source->template_parser_class, $this->smarty);

                return $this->compiler;

            // FIXME: routing of template -> smarty attributes
            default:
                if (property_exists($this->smarty, $property_name)) {
                    return $this->smarty->$property_name;
                }
        }

        throw new SmartyException("template property '$property_name' does not exist.");
    }

    /**
     * @param string $reason
     * @return void
     * @throws BrainyStrictModeException
     */
    public function assert_is_not_strict($reason)
    {
        if (Smarty::$strict_mode || $this->strict_mode) {
            throw new BrainyStrictModeException('Strict Mode: ' . $reason);
        }
    }

}
