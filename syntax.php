<?php

use dokuwiki\Extension\SyntaxPlugin;
use dokuwiki\File\PageResolver;

/**
 * DokuWiki Plugin bb4dw (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author Hans-Nikolai Viessmann <hans@viess.mn>
 *
 * This Syntax plugin is inspired by the deprecated publistf Dokuwiki plugin, and
 * tries to recreate the same output using a BibBrowser
 * (https://github.com/monperrus/bibtexbrowser) Bibtex processing script.
 *
 * Templating is handled through the BB4DWTemplating class.
 */

/**
 * BibBrowser Configurations
 */
$_GET['library'] = 1; // cause BibBrowser to run in 'library' mode
define('BIBTEXBROWSER_BIBTEX_LINKS', false); // disable links back to bibtex
define('USE_FIRST_THEN_LAST', true); // ensure that author names are consistently ordered

class syntax_plugin_bb4dw extends SyntaxPlugin
{
    /** @inheritDoc */
    public function getType()
    {
        return 'substition';
    }

    /** @inheritDoc */
    public function getPType()
    {
        return 'block';
    }

    /** @inheritDoc */
    public function getSort()
    {
        return 105;
    }

    /** @inheritDoc */
    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('\[bb4dw\|.+?\]', $mode, 'plugin_bb4dw');
    }

    /** @inheritDoc */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        $data = [
            'error' => false,
            'groups' => [],
            'bibtex' => [],
            'template' => [],
            'raw' => [],
            'config' => ['target' => 'dokuwiki',
                         'usegroup' => true,
                         'groupby' => 'year', # call also be 'none', 'author', or 'title'
                         'order' => 'newest'], # or 'descending'
        ];

        // parse the bb4dw plugin pattern
        $matchs = [];
        $pattern = '/\[bb4dw(?:\|bibtex=(dokuwiki|url):(.+?))(?:\|template=(dokuwiki|url):(.+?))(?:\|(.+?(?:\|.+?)*))?\]/';
        if (preg_match($pattern, $match, $matches) === 0) {
            msg('Not valid bb4dw syntax: '.$match, -1);
            $data['error'] = true;
        } else {
            // capture matches in config
            $data['bibtex'] = ['type' => $matches[1], 'ref' => $matches[2]];
            $data['template'] = ['type' => $matches[3], 'ref' => $matches[4]];

            if (!empty($matches[5])) {
               $matches = explode('|', $matches[5]);
               foreach ( $matches as $opt ) {
                 $optparts = array();
                 if (preg_match('/(.+?)=(.+)/', $opt, $optparts) ) {
                    $optparts[2] = explode(';', $optparts[2]);
                    $option = array();
                    foreach ($optparts[2] as $single) {
                        $single = explode('=', $single);
                        if (count($single) == 1 && count($optparts[2]) == 1) {
                            $option = $single[0];
                        }
                        else {
                            $option[$single[0]] = str_replace(',', '|', $single[1]);
                        }
                    }
                    $data['config'][$optparts[1]] = $option;
                 }
               }
            }

            // init BibBrowser library
            require_once(dirname(__FILE__).'/bibtexbrowser.php');
            global $db;
            $db = new BibDataBase();

            // Load Bibtex into db structure
            $db->load($this->retrieve_resource($data['bibtex']['type'], $data['bibtex']['ref'], true));

            // get all entries and sort (internally the default is by year)
            $data['raw'] = $db->getEntries();
            //uasort($data['raw'], 'compare_bib_entries');

            foreach ($data['raw'] as $entry) {
                // we decouple the read in fields from the bibbrowser library
                // we format authors into consistent state
                $_tmp_entryfields = $entry->getFields();
                $_tmp_entryfields['author'] = $entry->getFormattedAuthorsString();

                switch($data['config']['groupby']) {
                    case 'none':
                        $groupby = 'none';
                        break;
                    case 'author':
                        $_authors = $entry->getRawAuthors();
                        $groupby = mb_substr($entry->getLastName($_authors[0]), 0, 1);
                        break;
                    case 'title':
                        $groupby = mb_substr($entry->getTitle(), 0, 1);
                        break;
                    case 'year':
                        $groupby = $entry->getYear();
                        break;
                    default:
                        msg('Unknown groupby `'.$data['config']['groupby'].'` passed!', -1);
                        $data['error'] = true;
                        break 2;
                }

                // ensure that we don't append to null array
                if (empty($data['groups'][$groupby]))
                    $data['groups'][$groupby] = [$_tmp_entryfields];
                else
                    $data['groups'][$groupby][] = $_tmp_entryfields;
            }

            ksort($data['groups']);
            if ($data['config']['order'] == 'newest' || $data['config']['order'] == 'descending')
                $data['groups'] = array_reverse($data['groups'], true);
        }

        return $data;
    }

    /** @inheritDoc */
    public function render($mode, Doku_Renderer $renderer, $data)
    {
        if ($data['error']) return false;
        if ($mode !== 'xhtml') return false;

        // activate caching of results
        $renderer->info['cache'] = 0;

        $tpl = $this->retrieve_resource($data['template']['type'], $data['template']['ref']);

        require_once(dirname(__FILE__).'/templating.php');
        $bb4dw_tpl_class = new BB4DWTemplating();
        $proc_tpl = $bb4dw_tpl_class->process_template($tpl, $data);

        if ($data['config']['target'] == 'dokuwiki') {
            $proc_tpl = p_render($mode, p_get_instructions($proc_tpl), $info);
        }

        $renderer->doc .= $proc_tpl;

        return true;
    }

    /**
     * Retrieve resource from one of file, URL, or dokuwiki page. The result of this
     * function with either be the verbatim content of the resource, or an absolute path.
     *
     * @param string $type
     * @param string $ref
     * @param bool $path
     * @return string Content of or path to resource
     */
    private function retrieve_resource(string $type, string $ref, bool $path = false): string
    {
        global $INFO;

        $res = '';

        switch ($type)
        {
            case 'url':
                if ($path)
                    $res = $ref;
                else
                    $res = file_get_contents($ref);
                break;
            case 'dokuwiki':
                $resolver = new PageResolver($ID);
                $mid = $resolver->resolveId($ref);
                if(page_exists($mid)) {
                    if ($path)
                        $res = wikiFN($mid);
                    else
                        $res = rawWiki($mid);
                }
                break;
            default:
                msg('Unknown type '.$type.', unable to process!', -1);
                break;
        }

        return $res;
    }
}
