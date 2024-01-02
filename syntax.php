<?php

use dokuwiki\Extension\SyntaxPlugin;

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
        $data['config'] = ['target' => 'wiki', 'usegroup' => true, 'groupby' => 'year', 'order' => 'newest'];

        require_once(dirname(__FILE__).'/bibtexbrowser.php');
        global $db;
        $db = new BibDataBase();
        // FIXME we want to use WIKI page instead for this!
        $db->load(dirname(__FILE__).'/sample.bib');
        dbg("loading worked!");

        // get all entries and sort (internally the default is by year)
        $data['raw'] = $db->getEntries();
        uasort($data['raw'], 'compare_bib_entries');

        $groupby_func = '';
        switch($data['config']['groupby']) {
            case 'year':
                $groupby_func = 'getYear';
                break;
            default:
                msg('Unknown groupby passed!', -1);
                die();
                break;
        }

        foreach ($data['raw'] as $entry) {
            $groupby = $entry->$groupby_func();
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

        if ($data['config']['order'] == 'newest')
            $data['groups'] = array_reverse($data['groups'], true);

        $tpl = <<<TEMPLATE
        @{group@
        === @groupkey@ ===
        @{entry@
          * @?summary@<popover placement="top" trigger="hover" title="@title@" content="@summary@">@;summary@ **@title@** @author@ (@?year@@?month@@month@ @;month@@year@@;year@). @?booktitle@In //@booktitle@//.@;booktitle@ @?journal@//@journal@//@?volume@ @volume@@?number@ (@number@)@;number@@;volume@ @;journal@ @?pages@ pp. @pages@.@;pages@ @?institution@ //@institution@//.@;institution@@?publisher@ @publisher@.@;publisher@ @?address@ @address@.@;address@@?summary@</popover>@;summary@ @?doi@<button type="link" size="xs" icon="fa fa-book">[[http://dx.doi.org/@doi@|DOI]]</button>@;doi@@?url@{{publications:pdf:@url@?linkonly}}@;url@ @?bibtex@<button collapse="b_@globalkey@_@groupid@_@key@" type="link" size="xs" icon="fa fa-file-text">BibTex</button>@;bibtex@@?abstract@<button collapse="a_@globalkey@_@groupid@_@key@" type="link" size="xs" icon="fa fa-comment">Abstract</button>@;abstract@ @?bibtex@<collapse id="b_@globalkey@_@groupid@_@key@" collapsed="true"><code bibtex>@bibtex@</code></collapse>@;bibtex@ @?abstract@<collapse id="a_@globalkey@_@groupid@_@key@" collapsed="true"><well size="sm">@abstract@</well></collapse>@;abstract@
        @}entry@
        @}group@
        TEMPLATE;

        require_once(dirname(__FILE__).'/templating.php');
        $bb4dw_tpl_class = new BB4DWTemplating();
        $proc_tpl = $bb4dw_tpl_class->process_template($tpl, $data);

        if ($data['config']['target'] == 'wiki') {
            $proc_tpl = p_render($mode, p_get_instructions($proc_tpl), $info);
        }

        $renderer->doc .= $proc_tpl;

        return true;
    }
}
