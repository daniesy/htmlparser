<?php
namespace Daniesy\DOMinator\Nodes;

use Daniesy\DOMinator\Traits\HandlesAttributes;
use Daniesy\DOMinator\Traits\QueriesNodes;
use Daniesy\DOMinator\Traits\ModifiesNode;
use Daniesy\DOMinator\NodeList;
use Daniesy\DOMinator\CssParser;

// Represents a node in the HTML tree (element or text)
/**
 * @property string $innerText
 */

class Node {
    use QueriesNodes, ModifiesNode, HandlesAttributes;

    // Define constants for node types
    public const string NODE_TYPE_TEXT = 'text';
    public const string NODE_TYPE_COMMENT = 'comment';
    public const string NODE_TYPE_CDATA = 'cdata';
    public const string NODE_TYPE_ELEMENT = 'element';

    public NodeList $children;
    public ?Node $parent = null;
    public string $doctype = '';
    public string $xmlDeclaration = '';

    public function __construct(
        public string $tag = '',
        public array $attributes = [],
        public bool $isText = false,
        protected string $contents = '',
        public bool $isComment = false,
        public bool $isCdata = false,
        public string $namespace = ''
    ) {
        $this->children = new NodeList();
    }

    /**
     * Magic getter for node properties
     * 
     * @param string $name The property name
     * @return mixed The property value or null
     */
    public function __get(string $name): mixed {
        return match($name) {
            'innerText' => $this->getInnerText(),
            default => $this->$name ?? null
        };
    }
    
    /**
     * Magic setter for node properties
     * 
     * @param string $name The property name
     * @param mixed $value The value to set
     */
    public function __set(string $name, mixed $value): void {
        if ($name === 'innerText') {
            $this->setInnerText((string)$value);
            return;
        }
        $this->$name = $value;
    }
    
    public function toHtml(bool $minify = true, int $level = 0): string
    {
        $indent = ($minify ? '' : str_repeat("    ", $level));
        $newline = $minify ? '' : "\n";
        // Special handling: if this is the artificial root node, only export its children
        if ($this->tag === 'root') {
            $html = '';
            if (isset($this->xmlDeclaration) && $this->xmlDeclaration) {
                $html .= $this->xmlDeclaration . $newline;
            }
            if (isset($this->doctype) && $this->doctype) {
                $html .= $this->doctype . $newline;
            }
            foreach ($this->children as $child) {
                // Skip whitespace-only text nodes when pretty printing
                if (!$minify && $child->isText && trim($child->innerText) === '') {
                    continue;
                }
                $html .= $child->toHtml($minify, $minify ? 0 : $level);
                if (!$minify) $html .= $newline;
            }
            return $minify ? $html : rtrim($html, "\n");
        }
        // If this is the <html> node and has a doctype or xmlDeclaration, prepend them
        $html = '';
        if (isset($this->xmlDeclaration) && $this->xmlDeclaration) {
            $html .= $this->xmlDeclaration . $newline;
        }
        if ($this->tag === 'html' && isset($this->doctype) && $this->doctype) {
            $html .= $this->doctype . $newline;
        }
        if ($this->isComment) {
            return $indent . '<!--' . $this->innerText . '-->';
        }
        if ($this->isCdata) {
            return $indent . '<![CDATA[' . $this->innerText . ']]>';
        }
        if ($this->isText) {
            // For text nodes, don't add indentation in pretty print mode
            // If it's only whitespace and we're in pretty print mode, skip it
            $text = str_replace('&#039;', '&apos;', htmlspecialchars($this->innerText, ENT_QUOTES | ENT_HTML5));
            if (!$minify && trim($text) === '') {
                return '';
            }
            return $text;
        }
        $attr = '';
        foreach ($this->attributes as $k => $v) {
            $attr .= ' ' . $k . '="' . str_replace('&#039;', '&apos;', htmlspecialchars($v, ENT_QUOTES | ENT_HTML5)) . '"';
        }
        $html .= $indent . "<{$this->tag}{$attr}>";
        if ($this->children->length) {
            // Special handling for elements with only text content
            if ($this->children->length === 1 && $this->children->item(0)?->isText) {
                // For "title" tag in SVG, compress whitespace
                if ($this->tag === 'title' && $this->parent && $this->parent->tag === 'svg') {
                    $html .= trim(preg_replace('/\s+/', ' ', $this->children->item(0)->innerText));
                } else {
                    $html .= $this->children->item(0)->toHtml($minify, $level + 1);
                }
            } else {
                if (!$minify) $html .= $newline;
                foreach ($this->children as $child) {
                    // Skip whitespace-only text nodes when pretty printing
                    if (!$minify && $child->isText && trim($child->innerText) === '') {
                        continue;
                    }
                    $html .= $child->toHtml($minify, $level + 1);
                    if (!$minify) $html .= $newline;
                }
                $html .= $minify ? '' : $indent;
            }
        } else {
            $html .= str_replace('&#039;', '&apos;', htmlspecialchars($this->innerText, ENT_QUOTES | ENT_HTML5));
        }
        $html .= "</{$this->tag}>";
        return $html;
    }

    public function toInlinedHtml(bool $minify = true): string
    {
        $styleNodes = [];
        $allCssRules = [];
        // Collect all <style> nodes and parse their CSS
        $this->collectStyleNodes($styleNodes);
        foreach ($styleNodes as $styleNode) {
            $css = '';
            if (isset($styleNode->children)) {
                foreach ($styleNode->children as $child) {
                    if ($child) {
                        $css .= $child->innerText;
                    }
                }
            }
            $parsed = CssParser::parse($css);
            $allCssRules[] = [ 'node' => $styleNode, 'rules' => $parsed ];
        }
        // Map: selector => [rule, props, raw, styleNode]
        $selectorMap = [];
        foreach ($allCssRules as $block) {
            foreach ($block['rules'] as $rule) {
                if ($rule['type'] === 'rule') {
                    $selectorMap[] = [
                        'selector' => $rule['selector'],
                        'props' => $rule['props'],
                        'raw' => $rule['raw'],
                        'styleNode' => $block['node'],
                    ];
                }
            }
        }
        // Inline styles and track which rules were inlined
        $inlined = [];
        $this->applyAdvancedInlineStyles($selectorMap, $inlined);
        // Deep clone, removing only inlined rules from <style> tags
        $cloned = $this->deepCloneWithAdvancedStyleRemoval($allCssRules, $inlined);
        return $cloned->toHtml($minify);
    }

    private function collectStyleNodes(array &$styleNodes)
    {
        if ($this->tag === 'style') {
            $styleNodes[] = $this;
        }
        if (isset($this->children)) {
            foreach ($this->children as $child) {
                if ($child) {
                    $child->collectStyleNodes($styleNodes);
                }
            }
        }
    }

    // Applies all matching rules to this node and children, tracks inlined rules
    private function applyAdvancedInlineStyles(array $selectorMap, array &$inlined)
    {
        if ($this->tag && !$this->isText && !$this->isComment && !$this->isCdata) {
            $matchedProps = [];
            foreach ($selectorMap as $entry) {
                if (CssParser::matches($entry['selector'], $this)) {
                    foreach ($entry['props'] as $k => $v) {
                        $matchedProps[$k] = $v;
                    }
                    $nodeId = $entry['styleNode']->id ?? spl_object_id($entry['styleNode']);
                    $inlined[$nodeId][$entry['raw']] = true;
                }
            }
            if ($matchedProps) {
                $styleValue = '';
                foreach ($matchedProps as $k => $v) {
                    $styleValue .= $k . ': ' . $v . ';';
                }
                $this->attributes['style'] = $styleValue;
            }
        }
        
        if (isset($this->children)) {
            foreach ($this->children as $child) {
                if ($child) {
                    $child->applyAdvancedInlineStyles($selectorMap, $inlined);
                }
            }
        }
    }

    // Deep clone, but for <style> nodes, remove only rules that were actually inlined
    private function deepCloneWithAdvancedStyleRemoval(array $allCssRules, array $inlined): self|null
    {
        if ($this->tag === 'style') {
            $css = '';
            if (isset($this->children)) {
                foreach ($this->children as $child) {
                    if ($child) {
                        $css .= $child->innerText;
                    }
                }
            }
            $parsed = CssParser::parse($css);
            $kept = [];
            foreach ($parsed as $rule) {
                if ($rule['type'] === 'at') {
                    $kept[] = $rule['raw'];
                } elseif ($rule['type'] === 'rule') {
                    $sid = $this->id ?? spl_object_id($this);
                    if (!isset($inlined[$sid][$rule['raw']])) {
                        $kept[] = $rule['raw'];
                    }
                }
            }
            $newCss = implode(' ', $kept);
            if ($newCss === '') {
                return null;
            }
            $clone = new self('style', $this->attributes);
            if ($newCss !== '') {
                $clone->appendChild(new self('', [], true, $newCss));
            }
            return $clone;
        }
        $clone = new self(
            $this->tag,
            $this->attributes,
            $this->isText,
            $this->isText ? $this->innerText : '',
            $this->isComment,
            $this->isCdata,
            $this->namespace
        );
        $clone->doctype = $this->doctype ?? '';
        $clone->xmlDeclaration = $this->xmlDeclaration ?? '';
        
        if (isset($this->children)) {
            foreach ($this->children as $child) {
                if ($child) {
                    $clonedChild = $child->deepCloneWithAdvancedStyleRemoval($allCssRules, $inlined);
                    if ($clonedChild !== null) {
                        $clone->appendChild($clonedChild);
                    }
                }
            }
        }
        return $clone;
    }
}