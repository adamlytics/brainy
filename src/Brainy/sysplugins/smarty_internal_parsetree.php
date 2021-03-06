<?php
/**
 * Smarty Internal Plugin Templateparser Parsetrees
 *
 * These are classes to build parsetrees in the template parser
 *
 * @package Brainy
 * @subpackage Compiler
 * @author Thue Kristensen
 * @author Uwe Tews
 */

/**
 * @package Brainy
 * @subpackage Compiler
 * @ignore
 */
abstract class _smarty_parsetree
{
    /**
     * Parser object
     * @var object
     */
    public $parser;
    /**
     * Buffer content
     * @var mixed
     */
    public $data;

    /**
     * Return buffer
     *
     * @return string buffer content
     */
    abstract public function to_smarty_php();

    /**
     * Return buffer
     *
     * @return string buffer content
     */
    abstract public function to_inline_data();

    /**
     * @param string|null|void $data
     * @return string
     */
    public function echo_data($data = null) {
        if (is_null($data)) {
            $data = $this->to_inline_data();
        }
        if (strpos($data, '\\') === false) {
            $data = str_replace("'", "\\'", $data);
            return "echo '$data';\n";
        } else {
            return 'echo "' . $data . "\";\n";
        }
    }

    /**
     * Return escaped data
     *
     * @param string $toEscape
     * @return string escaped string
     */
    protected function escape_data($toEscape) {
        $out = str_replace("\n", '\n', $toEscape);
        $out = str_replace("\r", '\r', $out);
        $out = str_replace("\t", '\t', $out);
        $out = str_replace('$', '\$', $out);
        $out = str_replace('"', '\"', $out);
        return $out;
    }

    /**
     * @return bool
     */
    abstract public function can_combine_inline_data();

}

/**
 * A complete smarty tag.
 *
 * @package Brainy
 * @subpackage Compiler
 * @ignore
 */
class _smarty_tag extends _smarty_parsetree
{
    /**
     * Saved block nesting level
     * @var int
     */
    public $saved_block_nesting;

    /**
     * Create parse tree buffer for Smarty tag
     *
     * @param object $parser parser object
     * @param string $data   content
     */
    public function __construct($parser, $data) {
        $this->parser = $parser;
        $this->data = $data;
        $this->saved_block_nesting = $parser->block_nesting_level;
    }

    /**
     * @return string
     */
    public function to_inline_data() {
        return $this->data;
    }

    /**
     * Return buffer content
     *
     * @return string content
     */
    public function to_smarty_php() {
        return $this->data;
    }

    /**
     * Return complied code that loads the evaluated outout of buffer content into a temporary variable
     *
     * @return string template code
     */
    public function assign_to_var() {
        $var = sprintf('$_tmp%d', ++Smarty_Internal_Templateparser::$prefix_number);
        $this->parser->compiler->prefix_code[] = sprintf('ob_start();%s%s=ob_get_clean();', $this->data, $var);

        return $var;
    }

    /**
     * @return bool
     */
    public function can_combine_inline_data() {
        return false;
    }

}

/**
 * Code fragment inside a tag.
 *
 * @package Brainy
 * @subpackage Compiler
 * @ignore
 */
class _smarty_code extends _smarty_parsetree
{
    /**
     * Create parse tree buffer for code fragment
     *
     * @param object $parser parser object
     * @param string $data   content
     */
    public function __construct($parser, $data) {
        $this->parser = $parser;
        $this->data = $data;
    }

    /**
     * @return string
     */
    public function to_inline_data() {
        return $this->to_smarty_php();
    }

    /**
     * Return buffer content in parentheses
     *
     * @return string content
     */
    public function to_smarty_php() {
        return sprintf("(%s)", $this->data);
    }

    /**
     * @return bool
     */
    public function can_combine_inline_data() {
        return false;
    }

}

/**
 * Double quoted string inside a tag.
 *
 * @package Brainy
 * @subpackage Compiler
 * @ignore
 */
class _smarty_doublequoted extends _smarty_parsetree
{
    /**
     * Create parse tree buffer for double quoted string subtrees
     *
     * @param object            $parser  parser object
     * @param _smarty_parsetree $subtree parsetree buffer
     */
    public function __construct($parser, _smarty_parsetree $subtree) {
        $this->parser = $parser;
        $this->subtrees[] = $subtree;
        if ($subtree instanceof _smarty_tag) {
            $this->parser->block_nesting_level = count($this->parser->compiler->_tag_stack);
        }
    }

    /**
     * Append buffer to subtree
     *
     * @param _smarty_parsetree $subtree parsetree buffer
     */
    public function append_subtree(_smarty_parsetree $subtree) {
        $last_subtree = count($this->subtrees) - 1;
        if ($last_subtree >= 0 && $this->subtrees[$last_subtree] instanceof _smarty_tag && $this->subtrees[$last_subtree]->saved_block_nesting < $this->parser->block_nesting_level) {
            if ($subtree instanceof _smarty_code) {
                $this->subtrees[$last_subtree]->data .= 'echo ' . $subtree->data . ';';
            } elseif ($subtree instanceof _smarty_dq_content) {
                $this->subtrees[$last_subtree]->data .= $this->echo_data($subtree->data);
            } else {
                $this->subtrees[$last_subtree]->data .= $subtree->data;
            }
        } else {
            $this->subtrees[] = $subtree;
        }
        if ($subtree instanceof _smarty_tag) {
            $this->parser->block_nesting_level = count($this->parser->compiler->_tag_stack);
        }
    }

    /**
     * @return string
     */
    public function to_inline_data() {
        return $this->to_smarty_php();
    }

    /**
     * Merge subtree buffer content together
     *
     * @return string compiled template code
     */
    public function to_smarty_php() {
        $code = '';
        foreach ($this->subtrees as $subtree) {
            if ($code !== "") {
                $code .= ".";
            }
            if ($subtree instanceof _smarty_tag) {
                $more_php = $subtree->assign_to_var();
            } else {
                $more_php = $subtree->to_smarty_php();
            }

            $code .= $more_php;

            if (!$subtree instanceof _smarty_dq_content) {
                $this->parser->compiler->has_variable_string = true;
            }
        }

        return $code;
    }

    /**
     * @return bool
     */
    public function can_combine_inline_data() {
        return false;
    }

}

/**
 * Raw chars as part of a double quoted string.
 *
 * @package Brainy
 * @subpackage Compiler
 * @ignore
 */
class _smarty_dq_content extends _smarty_parsetree
{
    /**
     * Create parse tree buffer with string content
     *
     * @param object $parser parser object
     * @param string $data   string section
     */
    public function __construct($parser, $data) {
        $this->parser = $parser;
        $this->data = $data;
    }

    /**
     * @return string
     */
    public function to_inline_data() {
        return $this->data;
    }

    /**
     * Return content as double quoted string
     *
     * @return string doubled quoted string
     */
    public function to_smarty_php() {
        return '"' . $this->data . '"';
    }

    /**
     * @return bool
     */
    public function can_combine_inline_data() {
        return true;
    }

}

/**
 * Template element
 *
 * @package Brainy
 * @subpackage Compiler
 * @ignore
 */
class _smarty_template_buffer extends _smarty_parsetree
{
    /**
     * Array of template elements
     *
     * @var array
     */
    public $subtrees = Array();

    /**
     * Create root of parse tree for template elements
     *
     * @param object $parser parse object
     */
    public function __construct($parser) {
        $this->parser = $parser;
    }

    /**
     * Append buffer to subtree
     *
     * @param _smarty_parsetree $subtree
     */
    public function append_subtree(_smarty_parsetree $subtree) {
        $this->subtrees[] = $subtree;
    }

    /**
     * @return string
     */
    public function to_inline_data() {
        $code = '';
        for ($key = 0, $cnt = count($this->subtrees); $key < $cnt; $key++) {
            $code .= $this->subtrees[$key]->to_inline_data();
        }

        return $code . "\n";
    }

    /**
     * Sanitize and merge subtree buffers together
     *
     * @return string template code content
     */
    public function to_smarty_php() {
        $code = '';
        $buffer = '';
        for ($key = 0, $cnt = count($this->subtrees); $key < $cnt; $key++) {
            if ($key + 2 < $cnt &&
                $this->subtrees[$key] instanceof _smarty_linebreak &&
                $this->subtrees[$key + 1] instanceof _smarty_tag &&
                $this->subtrees[$key + 1]->data === '' &&
                $this->subtrees[$key + 2] instanceof _smarty_linebreak) {

                $key++;
                continue;
            }
            $node = $this->subtrees[$key];
            if ($node->can_combine_inline_data()) {
                $buffer .= $node->to_inline_data();
                continue;
            }
            if ($buffer !== '') {
                $code .= $this->echo_data($buffer);
                $buffer = '';
            }
            $code .= $node->to_smarty_php();
        }
        if ($buffer !== '') {
            $code .= $this->echo_data($buffer);
        }
        return $code;
    }

    /**
     * @return bool
     */
    public function can_combine_inline_data() {
        return false;
    }

}

/**
 * template text
 *
 * @package Brainy
 * @subpackage Compiler
 * @ignore
 */
class _smarty_text extends _smarty_parsetree
{
    /**
     * Create template text buffer
     *
     * @param object $parser parser object
     * @param string $data   text
     */
    public function __construct($parser, $data) {
        $this->parser = $parser;
        $this->data = $data;
    }

    /**
     * @return string
     */
    public function to_inline_data() {
        return $this->escape_data($this->data);
    }

    /**
     * Return buffer content
     *
     * @return strint text
     */
    public function to_smarty_php() {
        return $this->echo_data();
    }

    /**
     * @return bool
     */
    public function can_combine_inline_data() {
        return true;
    }

}

/**
 * template linebreaks
 *
 * @package Brainy
 * @subpackage Compiler
 * @ignore
 */
class _smarty_linebreak extends _smarty_parsetree
{
    /**
     * Create buffer with linebreak content
     *
     * @param object $parser parser object
     * @param string $data   linebreak string
     */
    public function __construct($parser, $data) {
        $this->parser = $parser;
        $this->data = $data;
    }

    /**
     * @return string
     */
    public function to_inline_data() {
        return $this->escape_data($this->data);
    }

    /**
     * Return linebreak
     *
     * @return string linebreak
     */
    public function to_smarty_php() {
        return $this->echo_data();
    }

    /**
     * @return bool
     */
    public function can_combine_inline_data() {
        return true;
    }

}
