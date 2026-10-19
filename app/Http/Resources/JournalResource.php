<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $date = ($this->logbook_date ?? $this->journal_date)?->toDateString();
        $verificationNote = $this->verification_note ?? $this->verification_comment;

        return [
            'id' => $this->id,
            'placement_id' => $this->placement_id,
            'attendance_id' => $this->attendance_id,
            'activity_id' => $this->activity_id,
            'logbook_date' => $date,
            'journal_date' => $date,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'activity' => $this->activity,
            'description' => $this->description,
            'result' => $this->result,
            'obstacles' => $this->obstacles,
            'photo_url' => $this->photo_path
                ? asset('storage/' . $this->photo_path)
                : null,
            'status' => $this->status,
            'submitted_at' => $this->submitted_at,
            'verified_at' => $this->verified_at,
            'verified_by' => $this->verified_by,
            'verification_note' => $verificationNote,
            'verification_comment' => $verificationNote,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'placement' => new PlacementResource($this->whenLoaded('placement')),
            'verifier' => new UserResource($this->whenLoaded('verifier')),
        ];
    }
}
