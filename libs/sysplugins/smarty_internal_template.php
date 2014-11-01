<?php
/**
 * Smarty Internal Plugin Template
 * This file contains the Smarty template engine
 *
 * @package    Smarty
 * @subpackage Template
 * @author     Uwe Tews
 */

/**
 * Main class with template data structures and methods
 *
 * @package    Smarty
 * @subpackage Template
 * @property Smarty_Template_Source   $source
 * @property Smarty_Template_Compiled $compiled
 * @property Smarty_Template_Cached   $cached
 */
class Smarty_Internal_Template extends Smarty_Internal_TemplateBase
{
    /**
     * cache_id
     *
     * @var string
     */
    public $cache_id = null;
    /**
     * $compile_id
     * @var string
     */
    public $compile_id = null;
    /**
     * caching enabled
     *
     * @var boolean
     */
    public $caching = null;
    /**
     * cache lifetime in seconds
     *
     * @var integer
     */
    public $cache_lifetime = null;
    /**
     * Template resource
     *
     * @var string
     */
    public $template_resource = null;
    /**
     * flag if compiled template is invalid and must be (re)compiled
     *
     * @var bool
     */
    public $mustCompile = null;
    /**
     * flag if template does contain nocache code sections
     *
     * @var bool
     */
    public $has_nocache_code = false;
    /**
     * special compiled and cached template properties
     *
     * @var array
     */
    public $properties = array('file_dependency' => array(),
                               'nocache_hash'    => '',
                               'tpl_function'    => array(),
                               'type'            => 'compiled',
    );
    /**
     * required plugins
     *
     * @var array
     */
    public $required_plugins = array('compiled' => array(), 'nocache' => array());
    /**
     * Global smarty instance
     *
     * @var Smarty
     */
    public $smarty = null;
    /**
     * blocks for template inheritance
     *
     * @var array
     */
    public $block_data = array();
    /**
     * variable filters
     *
     * @var array
     */
    public $variable_filters = array();
    /**
     * optional log of tag/attributes
     *
     * @var array
     */
    public $used_tags = array();
    /**
     * internal flag to allow relative path in child template blocks
     *
     * @var bool
     */
    public $allow_relative_path = false;
    /**
     * internal capture runtime stack
     *
     * @var array
     */
    public $_capture_stack = array(0 => array());

    /**
     * Create template data object
     * Some of the global Smarty settings copied to template scope
     * It load the required template resources and cacher plugins
     *
     * @param string                   $template_resource template resource string
     * @param Smarty                   $smarty            Smarty instance
     * @param Smarty_Internal_Template $_parent           back pointer to parent object with variables or null
     * @param mixed                    $_cache_id         cache   id or null
     * @param mixed                    $_compile_id       compile id or null
     * @param bool                     $_caching          use caching?
     * @param int                      $_cache_lifetime   cache life-time in seconds
     */
    public function __construct($template_resource, $smarty, $_parent = null, $_cache_id = null, $_compile_id = null, $_caching = null, $_cache_lifetime = null)
    {
        $this->smarty = &$smarty;
        // Smarty parameter
        $this->cache_id = $_cache_id === null ? $this->smarty->cache_id : $_cache_id;
        $this->compile_id = $_compile_id === null ? $this->smarty->compile_id : $_compile_id;
        $this->caching = $_caching === null ? $this->smarty->caching : $_caching;
        if ($this->caching === true) {
            $this->caching = Smarty::CACHING_LIFETIME_CURRENT;
        }
        $this->cache_lifetime = $_cache_lifetime === null ? $this->smarty->cache_lifetime : $_cache_lifetime;
        $this->parent = $_parent;
        // Template resource
        $this->template_resource = $template_resource;
        // copy block data of template inheritance
        if ($this->parent instanceof Smarty_Internal_Template) {
            $this->block_data = $this->parent->block_data;
        }
    }

    /**
     * Returns if the current template must be compiled by the Smarty compiler
     * It does compare the timestamps of template source and the compiled templates and checks the force compile configuration
     *
     * @throws SmartyException
     * @return boolean true if the template must be compiled
     */
    public function mustCompile()
    {
        if (!$this->source->exists) {
            if ($this->parent instanceof Smarty_Internal_Template) {
                $parent_resource = " in '$this->parent->template_resource}'";
            } else {
                $parent_resource = '';
            }
            throw new SmartyException("Unable to load template {$this->source->type} '{$this->source->name}'{$parent_resource}");
        }
        if ($this->mustCompile === null) {
            $this->mustCompile = (!$this->source->uncompiled && ($this->smarty->force_compile || $this->source->recompiled || $this->compiled->timestamp === false ||
                    ($this->smarty->compile_check && $this->compiled->timestamp < $this->source->timestamp)));
        }

        return $this->mustCompile;
    }

    /**
     * Compiles the template
     * If the template is not evaluated the compiled template is saved on disk
     */
    public function compileTemplateSource()
    {
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
        }
        catch (Exception $e) {
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
            if ($_filepath === false) {
                throw new SmartyException('getCompiledFilepath() did not return a destination to save the compiled template to');
            }
            Smarty_Internal_Write_File::writeFile($_filepath, $code, $this->smarty);
            $this->compiled->exists = true;
            $this->compiled->isCompiled = true;
        }
        // release compiler object to free memory
        unset($this->compiler);
    }

    /**
     * Writes the cached template output
     *
     * @param string $content
     *
     * @return bool
     */
    public function writeCachedContent($content)
    {
        if ($this->source->recompiled || !($this->caching == Smarty::CACHING_LIFETIME_CURRENT || $this->caching == Smarty::CACHING_LIFETIME_SAVED)) {
            // don't write cache file
            return false;
        }
        $this->cached->timestamp = time();
        $this->properties['cache_lifetime'] = $this->cache_lifetime;
        $this->properties['unifunc'] = 'content_' . str_replace(array('.', ','), '_', uniqid('', true));
        $content = $this->createTemplateCodeFrame($content, true);
        /** @var Smarty_Internal_Template $_smarty_tpl
         * used in evaluated code
         */
        /**
         * $_smarty_tpl = $this;
         * eval("?>" . $content);
         * $this->cached->valid = true;
         * $this->cached->processed = true;
         */
        return $this->cached->write($this, $content);
    }

    /**
     * Template code runtime function to get subtemplate content
     *
     * @param string  $template       the resource handle of the template file
     * @param mixed   $cache_id       cache id to be used with this template
     * @param mixed   $compile_id     compile id to be used with this template
     * @param integer $caching        cache mode
     * @param integer $cache_lifetime life time of cache data
     * @param         $data
     * @param int     $parent_scope   scope in which {include} should execute
     *
     * @returns string template content
     */
    public function getSubTemplate($template, $cache_id, $compile_id, $caching, $cache_lifetime, $data, $parent_scope)
    {
        // already in template cache?
        if ($this->smarty->allow_ambiguous_resources) {
            $_templateId = Smarty_Resource::getUniqueTemplateName($this, $template) . $cache_id . $compile_id;
        } else {
            $_templateId = $this->smarty->joined_template_dir . '#' . $template . $cache_id . $compile_id;
        }

        if (isset($_templateId[150])) {
            $_templateId = sha1($_templateId);
        }
        if (isset($this->smarty->template_objects[$_templateId])) {
            // clone cached template object because of possible recursive call
            $tpl = clone $this->smarty->template_objects[$_templateId];
            $tpl->parent = $this;
            if ((bool) $tpl->caching !== (bool) $caching) {
                unset($tpl->compiled);
            }
            $tpl->caching = $caching;
            $tpl->cache_lifetime = $cache_lifetime;
        } else {
            $tpl = new $this->smarty->template_class($template, $this->smarty, $this, $cache_id, $compile_id, $caching, $cache_lifetime);
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
        if (!empty($data)) {
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
     * @param mixed   $cache_id       cache id to be used with this template
     * @param mixed   $compile_id     compile id to be used with this template
     * @param integer $caching        cache mode
     * @param integer $cache_lifetime life time of cache data
     * @param         $data
     * @param int     $parent_scope   scope in which {include} should execute
     * @param string  $hash           nocache hash code
     *
     * @returns object template object
     */
    public function setupInlineSubTemplate($template, $cache_id, $compile_id, $caching, $cache_lifetime, $data, $parent_scope, $hash)
    {
        $tpl = new $this->smarty->template_class($template, $this->smarty, $this, $cache_id, $compile_id, $caching, $cache_lifetime);
        $tpl->properties['nocache_hash'] = $hash;
        $tpl->properties['tpl_function'] = $this->properties['tpl_function'];
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
     * Template code runtime function to set up an inline subtemplate
     *
     * @param string  $template       the resource handle of the template file
     * @param mixed   $cache_id       cache id to be used with this template
     * @param mixed   $compile_id     compile id to be used with this template
     * @param integer $caching        cache mode
     * @param integer $cache_lifetime life time of cache data
     * @param         $data
     * @param int     $parent_scope   scope in which {include} should execute
     * @param string  $hash           nocache hash code
     * @param string  $content_func   name of content function
     *
     * @returns object template content
     */
    public function getInlineSubTemplate($template, $cache_id, $compile_id, $caching, $cache_lifetime, $data, $parent_scope, $hash, $content_func)
    {
        $tpl = $this->setupInlineSubTemplate($template, $cache_id, $compile_id, $caching, $cache_lifetime, $data, $parent_scope, $hash);
        ob_start();
        $content_func($tpl);
        return str_replace($tpl->properties['nocache_hash'], $this->properties['nocache_hash'], ob_get_clean());
    }

    /**
     * Call template function
     *
     * @param string $name        template function name
     * @param object $_smarty_tpl template object
     * @param array  $params      parameter array
     * @param bool   $nocache     true if called nocache
     */
    public function callTemplateFunction($name, $_smarty_tpl, $params, $nocache)
    {
        if (isset($_smarty_tpl->properties['tpl_function']['param'][$name])) {
            if (!$_smarty_tpl->caching || ($_smarty_tpl->caching && $nocache) || $_smarty_tpl->properties['type'] !== 'cache') {
                $_smarty_tpl->properties['tpl_function']['to_cache'][$name] = true;
                $function = $_smarty_tpl->properties['tpl_function']['param'][$name]['call_name'];
            } else {
                if (isset($_smarty_tpl->properties['tpl_function']['param'][$name]['call_name_caching'])) {
                    $function = $_smarty_tpl->properties['tpl_function']['param'][$name]['call_name_caching'];
                } else {
                    $function = $_smarty_tpl->properties['tpl_function']['param'][$name]['call_name'];
                }
            }
            if (function_exists($function)) {
                $function ($_smarty_tpl, $params);
                return;
            }
            // try to load template function dynamically
            if (Smarty_Internal_Function_Call_Handler::call($name, $_smarty_tpl, $function, $params, $nocache)) {
                return;
            }
        }
        throw new SmartyException("Unable to find template function '{$name}'");
    }

    /**
     * Create code frame for compiled and cached templates
     *
     * @param  string $content optional template content
     * @param  bool   $cache   flag for cache file
     *
     * @return string
     */
    public function createTemplateCodeFrame($content = '', $cache = false)
    {
        $plugins_string = '';
        // include code for plugins
        if (!$cache) {
            if (!empty($this->required_plugins['compiled'])) {
                $plugins_string = '<?php ';
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
                $plugins_string .= '?>';
            }
            if (!empty($this->required_plugins['nocache'])) {
                $this->has_nocache_code = true;
                $plugins_string .= "<?php echo '/*%%SmartyNocache:{$this->properties['nocache_hash']}%%*/<?php \$_smarty = \$_smarty_tpl->smarty; ";
                foreach ($this->required_plugins['nocache'] as $tmp) {
                    foreach ($tmp as $data) {
                        $file = addslashes($data['file']);
                        if (is_Array($data['function'])) {
                            $plugins_string .= addslashes("if (!is_callable(array('{$data['function'][0]}','{$data['function'][1]}'))) include '{$file}';\n");
                        } else {
                            $plugins_string .= addslashes("if (!is_callable('{$data['function']}')) include '{$file}';\n");
                        }
                    }
                }
                $plugins_string .= "?>/*/%%SmartyNocache:{$this->properties['nocache_hash']}%%*/';?>\n";
            }
        }
        // build property code
        $this->properties['has_nocache_code'] = $this->has_nocache_code;
        $output = '<?php ';
        if (!$this->source->recompiled) {
            $output .= "/*%%SmartyHeaderCode:{$this->properties['nocache_hash']}%%*/";
            if ($this->smarty->direct_access_security) {
                $output .= "if(!defined('SMARTY_DIR')) exit('no direct access allowed');\n";
            }
        }
        if ($cache) {
            $this->properties['type'] = 'cache';
        } else {
            $this->properties['type'] = 'compiled';
        }
        $this->properties['version'] = Smarty::SMARTY_VERSION;
        if (!isset($this->properties['unifunc'])) {
            $this->properties['unifunc'] = 'content_' . str_replace(array('.', ','), '_', uniqid('', true));
        }
        if (!$this->source->recompiled) {
            $output .= "\$_valid = \$_smarty_tpl->decodeProperties(" . var_export($this->properties, true) . ',' . ($cache ? 'true' : 'false') . "); /*/%%SmartyHeaderCode%%*/?>\n";
            $output .= "<?php if (\$_valid && !is_callable('{$this->properties['unifunc']}')) {function {$this->properties['unifunc']} (\$_smarty_tpl) {\n";
        }
        $output .= "\$_saved_type = \$_smarty_tpl->properties['type'];\n";
        $output .= "\$_smarty_tpl->properties['type'] = \$_smarty_tpl->caching ? 'cache' : 'compiled';?>\n";
        $output .= $plugins_string . $content;
        $output .= "<?php \$_smarty_tpl->properties['type'] = \$_saved_type;?>\n";
        if (!$this->source->recompiled) {
            $output .= "<?php }} ?>\n";
        }
        if ($cache && isset($this->properties['tpl_function']['param'])) {
            $requiredFunctions = array();
            foreach ($this->properties['tpl_function']['param'] as $name => $param) {
                if (isset($this->properties['tpl_function']['to_cache'][$name])) {
                    $requiredFunctions[$param['compiled_filepath']][$name] = $param;
                }
            }
            foreach ($requiredFunctions as $filepath => $functions) {
                $code = file_get_contents($filepath);
                foreach ($functions as $name => $param) {
                    if (preg_match("/\/\* {$param['call_name']} \*\/([\S\s]*?)\/\*\/ {$param['call_name']} \*\//", $code, $match)) {
                        $output .= "<?php \n";
                        $output .= $match[0];
                        $output .= "?>\n";
                    }
                }
                unset($code, $match);
            }
        }
        return $output;
    }

    /**
     * This function is executed automatically when a compiled or cached template file is included
     * - Decode saved properties from compiled template and cache files
     * - Check if compiled or cache file is valid
     *
     * @param  array $properties special template properties
     * @param  bool  $cache      flag if called from cache file
     *
     * @return bool  flag if compiled or cache file is valid
     */
    public function decodeProperties($properties, $cache = false)
    {
        $properties['version'] = (isset($properties['version'])) ? $properties['version'] : '';
        $is_valid = true;
        if (Smarty::SMARTY_VERSION != $properties['version']) {
            // new version must rebuild
            $is_valid = false;
        } elseif (((!$cache && $this->smarty->compile_check && empty($this->compiled->_properties) && !$this->compiled->isCompiled) || $cache && ($this->smarty->compile_check === true || $this->smarty->compile_check === Smarty::COMPILECHECK_ON)) && !empty($properties['file_dependency'])) {
            // check file dependencies at compiled code
            foreach ($properties['file_dependency'] as $_file_to_check) {
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
        if ($cache) {
            // CACHING_LIFETIME_SAVED cache expiry has to be validated here since otherwise we'd define the unifunc
            if ($this->caching === Smarty::CACHING_LIFETIME_SAVED &&
                $properties['cache_lifetime'] >= 0 &&
                (time() > ($this->cached->timestamp + $properties['cache_lifetime']))
            ) {
                $is_valid = false;
            }
            $this->cached->valid = $is_valid;
        } else {
            $this->mustCompile = !$is_valid;
        }
        // store data in reusable Smarty_Template_Compiled
        if (!$cache) {
            $this->compiled->_properties = $properties;
        }
        if ($is_valid) {
            $this->has_nocache_code = $properties['has_nocache_code'];
            //            $this->properties['nocache_hash'] = $properties['nocache_hash'];
            if (isset($properties['cache_lifetime'])) {
                $this->properties['cache_lifetime'] = $properties['cache_lifetime'];
            }
            if (isset($properties['file_dependency'])) {
                $this->properties['file_dependency'] = array_merge($this->properties['file_dependency'], $properties['file_dependency']);
            }
            $this->properties['tpl_function']['param'] = isset($this->parent->properties['tpl_function']['param']) ? $this->parent->properties['tpl_function']['param'] : array();
            if (isset($properties['tpl_function']['param'])) {
                $this->properties['tpl_function']['param'] = array_merge($this->properties['tpl_function']['param'], $properties['tpl_function']['param']);
            }
            $this->properties['version'] = $properties['version'];
            $this->properties['unifunc'] = $properties['unifunc'];
            $this->properties['type'] = $properties['type'];
        }
        return $is_valid;
    }

    /**
     * Template code runtime function to create a local Smarty variable for array assignments
     *
     * @param string $tpl_var tempate variable name
     * @param bool   $nocache cache mode of variable
     * @param int    $scope   scope of variable
     */
    public function createLocalArrayVariable($tpl_var, $nocache = false, $scope = Smarty::SCOPE_LOCAL)
    {
        if (!isset($this->tpl_vars[$tpl_var])) {
            $this->tpl_vars[$tpl_var] = new Smarty_variable(array(), $nocache, $scope);
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
     * @param  int $scope requested variable scope
     *
     * @return array array of template variables
     */
    public function &getScope($scope)
    {
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
     * @param  int $scope pqrent or root scope
     *
     * @return mixed object
     */
    public function getScopePointer($scope)
    {
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
     *
     * @return int   the count for arrays and objects that implement countable, 1 for other objects that don't, and 0 for empty elements
     */
    public function _count($value)
    {
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

     */
    public function capture_error()
    {
        throw new SmartyException("Not matching {capture} open/close in \"{$this->template_resource}\"");
    }

    /**
     * Empty cache for this template
     *
     * @param integer $exp_time expiration time
     *
     * @return integer number of cache files deleted
     */
    public function clearCache($exp_time = null)
    {
        Smarty_CacheResource::invalidLoadedCache($this->smarty);

        return $this->cached->handler->clear($this->smarty, $this->template_name, $this->cache_id, $this->compile_id, $exp_time);
    }

    /**
     * set Smarty property in template context
     *
     * @param string $property_name property name
     * @param mixed  $value         value
     *
     * @throws SmartyException
     */
    public function __set($property_name, $value)
    {
        switch ($property_name) {
            case 'source':
            case 'compiled':
            case 'cached':
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
     *
     * @throws SmartyException
     */
    public function __get($property_name)
    {
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
                        $_templateId = $this->source->unique_resource . $this->cache_id . $this->compile_id;
                    } else {
                        $_templateId = $this->smarty->joined_template_dir . '#' . $this->template_resource . $this->cache_id . $this->compile_id;
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

            case 'cached':
                if (!class_exists('Smarty_Template_Cached')) {
                    include SMARTY_SYSPLUGINS_DIR . 'smarty_cacheresource.php';
                }
                $this->cached = new Smarty_Template_Cached($this);

                return $this->cached;

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
     * Template data object destructor

     */
    public function __destruct()
    {
        if ($this->smarty->cache_locking && isset($this->cached) && $this->cached->is_locked) {
            $this->cached->handler->releaseLock($this->smarty, $this->cached);
        }
    }
}
