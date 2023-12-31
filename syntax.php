<?php

use dokuwiki\Extension\SyntaxPlugin;

/**
 * DokuWiki Plugin bb4dw (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author Hans-Nikolai Viessmann <hans@viess.mn>
 */

/**
 * BibBrowser Configurations
 */
$_GET['library'] = 1; // cause BibBrowser to run in 'library' mode
define('BIBTEXBROWSER_BIBTEX_LINKS', false); // disable links back to bibtex
define('BIBTEXBROWSER_USE_PROGRESSIVE_ENHANCEMENT', false); // disable Javascript

// XXX this is just for initial testing before we add our own templating
define('BIBTEXBROWSER_LAYOUT','none');
define('BIBLIOGRAPHYSTYLE','JanosBibliographyStyle');

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
        $data = [];
        $data['groups'] = [];

        // set default config
        $data['config'] = ['groupby' => 'year'];

        require_once(dirname(__FILE__).'/bibtexbrowser.php');
        global $db;
        $db = new BibDataBase();
        // FIXME we want to use WIKI page instead for this!
        $db->load(dirname(__FILE__).'/sample.bib');
        dbg("loading worked!");

        // get all entries (FIXME is there a better way?)
        $query = array('year'=>'.*');
        $data['raw'] = $db->multisearch($query);
        dbg("filtering worked!");

        uasort($data['raw'], 'compare_bib_entries');
        foreach ($data['raw'] as $entry) {
            switch($data['config']['groupby']) {
                case 'year':
                    $groupby = $entry->getYear();
                    break;
                default:
                    msg('Unknown groupby passed!', -1);
                    break;
            }
            if (empty($data['groups'][$groupby]))
                $data['groups'][$groupby] = [$entry];
            else
                $data['groups'][$groupby][] = $entry;
        }
        dbg("shorting and grouping worked!");

        return $data;
    }

    /** @inheritDoc */
    public function render($mode, Doku_Renderer $renderer, $data)
    {
        if ($mode !== 'xhtml') return false;

        // activate caching of results
        $renderer->info['cache'] = 0;

        // FIXME this is just a prototype to show it working!
        foreach ($data['groups'] as $groupby => $group) {
            $renderer->doc .= '<h2>'.$groupby.'</h2>';
            $renderer->doc .= '<ul>';
            foreach ($group as $entry) {
              $renderer->doc .= '<li>'.$entry->toHTML().'</li>';
            }
            $renderer->doc .= '</ul>';
        }

        return true;
    }
}
