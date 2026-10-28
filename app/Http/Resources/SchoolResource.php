<?php

namespace App\Http\Resources;

use App\Services\PortalPublisher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SchoolResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sis_id' => $this->sis_id,
            'active' => $this->active,
            'guardian_notifications_enabled' => app(PortalPublisher::class)->enabledForSchool($this->resource),
            'timezone' => $this->timezone,
            'tax_rate' => $this->tax_rate,
            'tax_label' => $this->tax_label,
            'low_grade' => $this->low_grade,
            'high_grade' => $this->high_grade,
            'collect_tax' => $this->collect_tax,
            'currency_id' => $this->currency_id,
            'grade_levels' => $this->grade_levels,
            'school_number' => $this->school_number,
            'default_title' => $this->default_title,
            'tax_rate_formatted' => $this->tax_rate_formatted,
            'tax_rate_converted' => $this->tax_rate_converted,
            'include_draft_stamp' => $this->include_draft_stamp,
            'invoice_number_template' => $this->invoice_number_template,
            'currency' => new CurrencyResource($this->whenLoaded('currency')),
        ];
    }
}
