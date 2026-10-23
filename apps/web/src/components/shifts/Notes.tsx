"use client";

import { useTranslations } from "next-intl";
import { useState } from "react";
import { ApiError } from "@/lib/api/client";
import { addNote } from "@/lib/api/counts";
import type { HandoverNote, RosterRow } from "@/lib/api/types";
import { useErrorText } from "@/lib/errors";
import { addisClock } from "@/lib/shifts";
import { Alert, Button, Field, Select, Textarea } from "../ui";
import { useKeys } from "./useKeys";

// Handover notes (step 3.7b): the list of what the last shift left, and the form that writes one (POST /shifts/{id}/notes). A note is
// append-only: there is nothing to edit or delete. A note may be addressed to one person on the shift; the others still see it.

export function NotesList({ notes }: { notes: HandoverNote[] }) {
  const t = useTranslations("counts");
  if (notes.length === 0) return <p className="text-base text-neutral-600">{t("notes_none")}</p>;
  return (
    <ul className="space-y-3">
      {notes.map((note) => (
        <li key={note.id} className="rounded-md border border-neutral-200 bg-neutral-50 px-4 py-3">
          <p className="text-sm text-neutral-600">
            {note.from?.name ?? ""} · {addisClock(note.created_at)}
            {note.to ? ` · ${t("note_to", { name: note.to.name })}` : ""}
          </p>
          <p className="whitespace-pre-line text-base text-neutral-900">{note.body}</p>
        </li>
      ))}
    </ul>
  );
}

export function NoteForm({ shiftId, roster, onSaved }: { shiftId: number; roster: RosterRow[]; onSaved: (note: HandoverNote) => void }) {
  const t = useTranslations("counts");
  const errorText = useErrorText();
  const keys = useKeys();
  const [body, setBody] = useState("");
  const [to, setTo] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<unknown>(null);

  const people = roster.flatMap((row) => (row.user ? [row.user] : []));

  async function save() {
    const text = body.trim();
    if (!text || busy) return;
    const name = `note:${shiftId}:${to ?? ""}:${text}`;
    setBusy(true);
    setFailure(null);
    try {
      const note = await addNote(shiftId, text, to, keys.keyFor(name));
      keys.done(name);
      setBody("");
      onSaved(note);
    } catch (error) {
      setFailure(error);
      // a definite answer ends this attempt; only a lost connection retries under the same key
      if (!(error instanceof ApiError) || error.status !== 0) keys.done(name);
    } finally {
      setBusy(false);
    }
  }

  return (
    <form
      className="space-y-3"
      onSubmit={(event) => {
        event.preventDefault();
        void save();
      }}
    >
      <Field label={t("note_label")}>
        {(props) => <Textarea {...props} value={body} maxLength={2000} onChange={(event) => setBody(event.target.value)} />}
      </Field>
      {people.length > 0 ? (
        <div className="w-64">
          <Field label={t("note_for")}>
            {(props) => (
              <Select {...props} value={to ?? ""} onChange={(event) => setTo(event.target.value === "" ? null : Number(event.target.value))}>
                <option value="">{t("note_everyone")}</option>
                {people.map((person) => (
                  <option key={person.id} value={person.id}>
                    {person.name}
                  </option>
                ))}
              </Select>
            )}
          </Field>
        </div>
      ) : null}
      {failure ? <Alert>{errorText.message(failure)}</Alert> : null}
      <Button type="submit" disabled={busy || body.trim() === ""}>
        {busy ? t("working") : t("note_add")}
      </Button>
    </form>
  );
}
