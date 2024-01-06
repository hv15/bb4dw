<?php
/**
 * DokuWiki Plugin bb4dw (Templating Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author Hans-Nikolai Viessmann <hans@viess.mn>
 *
 * This class is based upon the wirking in the deprecated publistf plugin
 * and the bib2tpl (https://github.com/reitzig/bib2tpl) project. We make use
 * of the exactly the same templating mechanism, specifically how conditionals
 * are structured and parsed.
 *
 * At its core, this class resolves a template of the format:
 *
 * ```
 * @{group@
 * === @groupkey@ ===
 * @{entry@
 *   # further tokens like
 *   @key@ @title@ @author@
 * @}entry@
 * @}group@
 * ```
 *
 * where a *group* contains multiple *entries*. All tokens are encoded using `@`
 * (at) symbols, these either (i) refer to context specific values, or (ii) to
 * values stored within the entry, in our case Bibtex.
 *
 * **Note that tokens which exist in neither case are left untouched and appear in
 * the output!**
 */
class BB4DWTemplating
{
    /**
     * This function handles using 'templates' to specify the desired structure
     * of the bib entries in DokuWiki.
     *
     * @param string $tpl The template given as a multiline string
     * @param array $data Array containing *groups* and *config* values
     * @return string The processed template with all tokens replaced
     */
    function process_template(string $tpl, array $data): string {
        $groups = $data['groups'];
        $result = $tpl;

        // FIXME globalcount is currently not used
        $result = preg_replace(['/@globalcount@/', '/@globalgroupcount@/', '/@globalkey@/'],
                               [0, count($groups), $data['config']['groupby']], $result);

        if ($data['config']['usegroup']) {
            $pattern = '/@\{group@(.*?)@\}group@/s';
            $group_tpl = [];
            preg_match($pattern, $result, $group_tpl);

            while (!empty($group_tpl)) {
                $groups_res = '';
                $id = 0;

                foreach ($groups as $groupkey => $group) {
                    $groups_res .= $this->process_tpl_group($groupkey, $id++, $group, $group_tpl[1]);
                }

                $result = preg_replace($pattern, $groups_res, $result, 1);
                preg_match($pattern, $result, $group_tpl);
            }
        }

        return $result;
    }

    /**
     * Process group-level template features
     *
     * @param string $groupkey The group name or keyword
     * @param int $id The group numeric ID or index
     * @param array $group Array containing the *entries*
     * @param string $tpl The template to be processed
     * @return string The processed template for the group
     */
    private function process_tpl_group(string $groupkey, int $id, array $group, string $tpl): string {
        $result = $tpl;

        //if ( $this->options['group'] === 'entrytype' ) {
        //    $key = $this->options['lang']['entrytypes'][$key];
        //}
        $result = preg_replace(['/@groupkey@/', '/@groupid@/', '/@groupcount@/'],
                               [$groupkey, $id, count($group)],
                               $result);

        $pattern = '/@\{entry@(.*?)@\}entry@/s';

        // Extract entry templates
        $entry_tpl = array();
        preg_match($pattern, $result, $entry_tpl);

        // For all occurrences of an entry template
        while ( !empty($entry_tpl) ) {
            // Translate all entries
            $entries_res = '';
            foreach ($group as $entryfields) {
                $entries_res .= $this->process_tpl_entry($entryfields, $entry_tpl[1]);
            }

            $result = preg_replace($pattern, $entries_res, $result, 1);
            preg_match($pattern, $result, $entry_tpl);
        }

        return $result;
    }

    /**
     * Process entry-level template features
     *
     * @param BibEntry $entry A single *entry*
     * @param string $tpl The template to be processed
     * @return string The processed template for the group
     */
    private function process_tpl_entry(array $entryfields, string $tpl): string {
        $result = $tpl;

        // XXX bib2tpl template uses `entrykey` for Bibtex `key`, here we manually
        //     replace this rather than adding an additional field into the entry array.
        $result = preg_replace(['/@entrykey@/'],
                               [$entryfields['key']],
                               $result);

        // Resolve all conditions
        $result = $this->resolve_conditions($entryfields, $result);

        // Replace all possible unconditional fields
        $patterns = [];
        $replacements = [];

        foreach ($entryfields as $key => $value) {
            //if ( $key === 'author' ) {
            //    $value = $entry['niceauthor'];
            //    $value = $this->authorlink($value);
            //}
            $patterns[] = '/@'.$key.'@/';
            $replacements[] = $value;
        }

        return preg_replace($patterns, $replacements, $result);
    }

    /**
     * This function eliminates conditions in template parts.
     *
     * @param array entry Entry with respect to which conditions are to be
     *                    solved.
     * @param string template The entry part of the template.
     * @return string Template string without conditions.
     */
    private function resolve_conditions(array $entry, string &$string): string {
        $pattern = '/@\?(\w+)(?:(<=|>=|==|!=|~)(.*?))?@(.*?)(?:@:\1@(.*?))?@;\1@/s';
        /* There are two possibilities for mode: existential or value check
         * Then, there can be an else part or not.
         *          Existential       Value Check      RegExp
         * Group 1  field             field            \w+
         * Group 2  then              operator         .*?  /  <=|>=|==|!=|~
         * Group 3  [else]            value            .*?
         * Group 4   ---              then             .*?
         * Group 5   ---              [else]           .*?
         */

        $match = [];

        /* Would like to do
         *    preg_match_all($pattern, $string, $matches);
         * to get all matches at once but that results in Segmentation
         * fault. Therefore iteratively:
         */
        while (preg_match($pattern, $string, $match)) {
            $resolved = '';

            $evalcond = !empty($entry[$match[1]]);
            $then = count($match) > 3 ? 4 : 2;
            $else = count($match) > 3 ? 5 : 3;

            if ( $evalcond && count($match) > 3 ) {
                if ( $match[2] === '==' ) {
                    $evalcond = $entry[$match[1]] === $match[3];
                }
                elseif ( $match[2] === '!=' ) {
                    $evalcond = $entry[$match[1]] !== $match[3];
                }
                elseif ( $match[2] === '<=' ) {
                    $evalcond =    is_numeric($entry[$match[1]])
                        && is_numeric($match[3])
                        && (int)$entry[$match[1]] <= (int)$match[3];
                }
                elseif ( $match[2] === '>=' ) {
                    $evalcond =    is_numeric($entry[$match[1]])
                        && is_numeric($match[3])
                        && (int)$entry[$match[1]] >= (int)$match[3];
                }
                elseif ( $match[2] === '~' ) {
                    $evalcond = preg_match('/'.$match[3].'/', $entry[$match[1]]) > 0;
                }
            }

            if ( $evalcond )
            {
                $resolved = $match[$then];
            }
            elseif ( !empty($match[$else]) )
            {
                $resolved = $match[$else];
            }

            // Recurse to cope with nested conditions
            $resolved = $this->resolve_conditions($entry, $resolved);

            $string = str_replace($match[0], $resolved, $string);
        }

        return $string;
    }

}

?>
