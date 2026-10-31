<?php

/**
 * app/views/partials/desc-toolbar.php — the formatting toolbar and the
 * character counter under a description textarea. The dashboard has two, in
 * the file dialog and in the folder dialog, and they must stay the same
 * widget: dashboard.js binds every .desc-toolbar to the textarea named in
 * data-target and finds the counter at "<id>-counter".
 *
 * Below the textarea, not above: the native selection popup appears above
 * the selection and would cover a toolbar placed there. The counter shares
 * the row (margin-left: auto) and starts from DESCRIPTION_MAX, the limit the
 * textarea's maxlength and the server apply.
 *
 * Input variable (set before each include):
 *   string $descTarget  id of the textarea this toolbar edits
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

?>
            <div class="desc-toolbar-row">
                <div class="desc-toolbar" data-target="<?= e($descTarget) ?>">
                    <button type="button" class="btn btn-ghost btn-sm desc-fmt" data-md="**" title="<?= e(__("desc_fmt_bold")) ?>"><b>B</b></button>
                    <button type="button" class="btn btn-ghost btn-sm desc-fmt" data-md="*" title="<?= e(__("desc_fmt_italic")) ?>"><i>I</i></button>
                    <button type="button" class="btn btn-ghost btn-sm desc-fmt" data-md="~~" title="<?= e(__("desc_fmt_strike")) ?>"><s>S</s></button>
                    <button type="button" class="btn btn-ghost btn-sm desc-fmt" data-md="quote" title="<?= e(__("desc_fmt_quote")) ?>">&#10077;&#10078;</button>
                    <button type="button" class="btn btn-ghost btn-sm desc-fmt" data-md="`" title="<?= e(__("desc_fmt_code")) ?>"><code>&lt;/&gt;</code></button>
                    <button type="button" class="btn btn-ghost btn-sm desc-fmt" data-md="link" title="<?= e(__("desc_fmt_link")) ?>"><?= icon("link") ?></button>
                </div>
                <div class="desc-counter" id="<?= e($descTarget) ?>-counter">0/<?= DESCRIPTION_MAX ?></div>
            </div>
