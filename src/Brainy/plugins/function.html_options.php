<?php
/**
 * Smarty plugin
 *
 * @package Brainy
 * @subpackage PluginsFunction
 */

/**
 * Smarty {html_options} function plugin
 *
 * Type:     function<br>
 * Name:     html_options<br>
 * Purpose:  Prints the list of <option> tags generated from
 *           the passed parameters<br>
 * Params:
 * <pre>
 * - name       (optional) - string default "select"
 * - values     (required) - if no options supplied) - array
 * - options    (required) - if no values supplied) - associative array
 * - selected   (optional) - string default not set
 * - output     (required) - if not options supplied) - array
 * - id         (optional) - string default not set
 * - class      (optional) - string default not set
 * </pre>
 *
 * @link http://www.smarty.net/manual/en/language.function.html.options.php {html_image}
 *      (Smarty online manual)
 * @author Monte Ohrt <monte at ohrt dot com>
 * @author Ralf Strehle (minor optimization) <ralf dot strehle at yahoo dot de>
 * @param array                    $params   parameters
 * @param Smarty_Internal_Template $template template object
 * @return string
 * @uses smarty_function_escape_special_chars()
 */
function smarty_function_html_options($params, $template) {

    $template->assert_is_not_strict('`{html_options}` is a deprecated plugin and is not allowed in strict mode');

    require_once(SMARTY_PLUGINS_DIR . 'shared.escape_special_chars.php');

    $options = array(
        'name' => null,
        'values' => null,
        'options' => null,
        'selected' => null,
        'output' => null,
        'id' => null,
        'class' => null,

        'extra' => '',
    );

    foreach ($params as $_key => $_val) {
        switch ($_key) {
            case 'name':
            case 'class':
            case 'id':
                $options[$_key] = (string) $_val;
                break;

            case 'options':
                $options['options'] = (array) $_val;
                break;

            case 'values':
            case 'output':
                $options[$_key] = array_values((array) $_val);
                break;

            case 'selected':
                if (is_array($_val)) {
                    $options['selected'] = array();
                    foreach ($_val as $_sel) {
                        if (is_object($_sel)) {
                            if (method_exists($_sel, "__toString")) {
                                $_sel = smarty_function_escape_special_chars((string) $_sel->__toString());
                            } else {
                                trigger_error("html_options: selected attribute contains an object of class '". get_class($_sel) ."' without __toString() method", E_USER_NOTICE);
                                continue;
                            }
                        } else {
                            $_sel = smarty_function_escape_special_chars((string) $_sel);
                        }
                        $options['selected'][$_sel] = true;
                    }
                } elseif (is_object($_val)) {
                    if (method_exists($_val, "__toString")) {
                        $options['selected'] = smarty_function_escape_special_chars((string) $_val->__toString());
                    } else {
                        trigger_error("html_options: selected attribute is an object of class '". get_class($_val) ."' without __toString() method", E_USER_NOTICE);
                    }
                } else {
                    $options['selected'] = smarty_function_escape_special_chars((string) $_val);
                }
                break;

            case 'strict': break;

            case 'disabled':
            case 'readonly':
                if (!empty($params['strict'])) {
                    if (!is_scalar($_val)) {
                        trigger_error("html_options: $_key attribute must be a scalar, only boolean true or string '$_key' will actually add the attribute", E_USER_NOTICE);
                    }

                    if ($_val === true || $_val === $_key) {
                        $options['extra'] .= ' ' . $_key . '="' . smarty_function_escape_special_chars($_key) . '"';
                    }

                    break;
                }
                // omit break; to fall through!

            default:
                if (!is_array($_val)) {
                    $options['extra'] .= ' ' . $_key . '="' . smarty_function_escape_special_chars($_val) . '"';
                } else {
                    trigger_error("html_options: extra attribute '$_key' cannot be an array", E_USER_NOTICE);
                }
                break;
        }
    }

    if (empty($options['options']) && empty($options['values'])) {
        /* raise error here? */

        return '';
    }

    $_html_result = '';
    $_idx = 0;

    if (isset($options['options'])) {
        foreach ($options['options'] as $_key => $_val) {
            $_html_result .= smarty_function_html_options_optoutput($_key, $_val, $options['selected'], $options['id'], $options['class'], $_idx);
        }
    } else {
        foreach ($options['values'] as $_i => $_key) {
            $_val = isset($options['output'][$_i]) ? $options['output'][$_i] : '';
            $_html_result .= smarty_function_html_options_optoutput($_key, $_val, $options['selected'], $options['id'], $options['class'], $_idx);
        }
    }

    if (!empty($options['name'])) {
        $_html_class = !empty($options['class']) ? ' class="'.$options['class'].'"' : '';
        $_html_id = !empty($options['id']) ? ' id="'.$options['id'].'"' : '';
        $_html_result = '<select name="' . $options['name'] . '"' . $_html_class . $_html_id . $options['extra'] . '>' . "\n" . $_html_result . '</select>' . "\n";
    }

    return $_html_result;
}

function smarty_function_html_options_optoutput($key, $value, $selected, $id, $class, &$idx) {
    if (!is_array($value)) {
        $_key = smarty_function_escape_special_chars($key);
        $_html_result = '<option value="' . $_key . '"';
        if (is_array($selected)) {
            if (isset($selected[$_key])) {
                $_html_result .= ' selected="selected"';
            }
        } elseif ($_key === $selected) {
            $_html_result .= ' selected="selected"';
        }
        $_html_class = !empty($class) ? ' class="'.$class.' option"' : '';
        $_html_id = !empty($id) ? ' id="'.$id.'-'.$idx.'"' : '';
        if (is_object($value)) {
            if (method_exists($value, "__toString")) {
                $value = smarty_function_escape_special_chars((string) $value->__toString());
            } else {
                trigger_error("html_options: value is an object of class '". get_class($value) ."' without __toString() method", E_USER_NOTICE);

                return '';
            }
        } else {
            $value = smarty_function_escape_special_chars((string) $value);
        }
        $_html_result .= $_html_class . $_html_id . '>' . $value . '</option>' . "\n";
        $idx++;
    } else {
        $_idx = 0;
        $_html_result = smarty_function_html_options_optgroup($key, $value, $selected, !empty($id) ? ($id.'-'.$idx) : null, $class, $_idx);
        $idx++;
    }

    return $_html_result;
}

function smarty_function_html_options_optgroup($key, $values, $selected, $id, $class, &$idx) {
    $optgroup_html = '<optgroup label="' . smarty_function_escape_special_chars($key) . '">' . "\n";
    foreach ($values as $key => $value) {
        $optgroup_html .= smarty_function_html_options_optoutput($key, $value, $selected, $id, $class, $idx);
    }
    $optgroup_html .= "</optgroup>\n";

    return $optgroup_html;
}
