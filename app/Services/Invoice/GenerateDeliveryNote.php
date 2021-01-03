<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2021. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://opensource.org/licenses/AAL
 */

namespace App\Services\Invoice;

use App\Models\ClientContact;
use App\Models\Design;
use App\Models\Invoice;
use App\Services\PdfMaker\Design as PdfMakerDesign;
use App\Services\PdfMaker\PdfMaker as PdfMakerService;
use App\Utils\HtmlEngine;
use App\Utils\Traits\MakesHash;
use App\Utils\Traits\Pdf\PdfMaker;
use Illuminate\Support\Facades\Storage;

class GenerateDeliveryNote
{
    use MakesHash, PdfMaker;

    /**
     * @var \App\Models\Invoice
     */
    private $invoice;

    /**
     * @var \App\Models\ClientContact
     */
    private $contact;

    /**
     * @var mixed
     */
    private $disk;

    public function __construct(Invoice $invoice, ClientContact $contact = null, $disk = null)
    {
        $this->invoice = $invoice;

        $this->contact = $contact;

        $this->disk = $disk ?? config('filesystems.default');
    }

    public function run()
    {
        $design_id = $this->invoice->design_id
            ? $this->invoice->design_id
            : $this->decodePrimaryKey($this->invoice->client->getSetting('invoice_design_id'));

        $file_path = sprintf('%s%s_delivery_note.pdf', $this->invoice->client->invoice_filepath(), $this->invoice->number);

        $design = Design::find($design_id);
        $html = new HtmlEngine($this->invoice->invitations->first());

        if ($design->is_custom) {
            $options = ['custom_partials' => json_decode(json_encode($design->design), true)];
            $template = new PdfMakerDesign(PdfMakerDesign::CUSTOM, $options);
        } else {
            $template = new PdfMakerDesign(strtolower($design->name));
        }

        $state = [
            'template' => $template->elements([
                'client' => $this->invoice->client,
                'entity' => $this->invoice,
                'pdf_variables' => (array) $this->invoice->company->settings->pdf_variables,
                'contact' => $this->contact,
            ], 'delivery_note'),
            'variables' => $html->generateLabelsAndValues(),
        ];

        $maker = new PdfMakerService($state);

        $maker
            ->design($template)
            ->build();

        // Storage::makeDirectory($this->invoice->client->invoice_filepath(), 0775);

        $pdf = $this->makePdf(null, null, $maker->getCompiledHTML());

        if (config('ninja.log_pdf_html')) {
            info($maker->getCompiledHTML());
        }

        Storage::disk($this->disk)->put($file_path, $pdf);

        return $file_path;
    }
}
