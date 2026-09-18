import type React from 'react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

export type FieldDef = {
    name: string;
    label: string;
    type?:
        | 'text'
        | 'email'
        | 'number'
        | 'date'
        | 'datetime'
        | 'textarea'
        | 'password'
        | 'select'
        | 'checkbox';
    options?: { value: string; label: string }[];
    required?: boolean;
    placeholder?: string;
    /** Lebar penuh (default 1 kolom dari 2). */
    wide?: boolean;
    help?: string;
};

export type FieldValues = Record<string, string | number | boolean | null>;

type ResourceDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description?: string;
    fields: FieldDef[];
    initial?: FieldValues;
    submitLabel?: string;
    submitting?: boolean;
    onSubmit: (values: FieldValues) => void;
};

function emptyValue(field: FieldDef): string | number | boolean {
    if (field.type === 'checkbox') {
        return false;
    }

    if (field.type === 'number') {
        return '';
    }

    return '';
}

/**
 * Dialog form generik (dipakai halaman master data & order).
 *
 * Nilai dikirim apa adanya; konversi (mis. `Number(...)`) dilakukan pemanggil
 * lewat `onSubmit` — supaya validasi tetap di server.
 */
export function ResourceDialog({
    open,
    onOpenChange,
    title,
    description,
    fields,
    initial,
    submitLabel = 'Simpan',
    submitting = false,
    onSubmit,
}: ResourceDialogProps) {
    const [values, setValues] = useState<FieldValues>({});

    useEffect(() => {
        if (!open) {
            return;
        }

        const next: FieldValues = {};

        for (const field of fields) {
            const value = initial?.[field.name];
            next[field.name] =
                value === undefined || value === null
                    ? emptyValue(field)
                    : (value as string | number | boolean);
        }

        setValues(next);
        // Sengaja hanya bergantung pada `open`: reset form tiap dialog dibuka.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    function set(name: string, value: string | number | boolean) {
        setValues((prev) => ({ ...prev, [name]: value }));
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    {description && (
                        <DialogDescription>{description}</DialogDescription>
                    )}
                </DialogHeader>

                <form
                    className="grid gap-3 sm:grid-cols-2"
                    onSubmit={(e: React.FormEvent) => {
                        e.preventDefault();
                        onSubmit(values);
                    }}
                >
                    {fields.map((field) => (
                        <div
                            key={field.name}
                            className={
                                field.wide ||
                                field.type === 'textarea' ||
                                field.type === 'checkbox'
                                    ? 'space-y-1 sm:col-span-2'
                                    : 'space-y-1'
                            }
                        >
                            {field.type === 'checkbox' ? (
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        className="size-4"
                                        checked={Boolean(values[field.name])}
                                        onChange={(e) =>
                                            set(field.name, e.target.checked)
                                        }
                                    />
                                    {field.label}
                                </label>
                            ) : (
                                <>
                                    <Label htmlFor={`field-${field.name}`}>
                                        {field.label}
                                        {field.required && (
                                            <span className="text-red-500">
                                                {' '}
                                                *
                                            </span>
                                        )}
                                    </Label>

                                    {field.type === 'textarea' ? (
                                        <Textarea
                                            id={`field-${field.name}`}
                                            rows={3}
                                            placeholder={field.placeholder}
                                            value={String(
                                                values[field.name] ?? '',
                                            )}
                                            onChange={(e) =>
                                                set(field.name, e.target.value)
                                            }
                                        />
                                    ) : field.type === 'select' ? (
                                        <select
                                            id={`field-${field.name}`}
                                            className="bg-background w-full rounded-md border px-3 py-2 text-sm"
                                            value={String(
                                                values[field.name] ?? '',
                                            )}
                                            onChange={(e) =>
                                                set(field.name, e.target.value)
                                            }
                                        >
                                            <option value="">— pilih —</option>
                                            {(field.options ?? []).map(
                                                (option) => (
                                                    <option
                                                        key={option.value}
                                                        value={option.value}
                                                    >
                                                        {option.label}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    ) : (
                                        <Input
                                            id={`field-${field.name}`}
                                            type={
                                                field.type === 'datetime'
                                                    ? 'datetime-local'
                                                    : (field.type ?? 'text')
                                            }
                                            placeholder={field.placeholder}
                                            value={String(
                                                values[field.name] ?? '',
                                            )}
                                            onChange={(e) =>
                                                set(field.name, e.target.value)
                                            }
                                        />
                                    )}

                                    {field.help && (
                                        <p className="text-muted-foreground text-xs">
                                            {field.help}
                                        </p>
                                    )}
                                </>
                            )}
                        </div>
                    ))}

                    <DialogFooter className="sm:col-span-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Batal
                        </Button>
                        <Button type="submit" disabled={submitting}>
                            {submitting ? 'Menyimpan…' : submitLabel}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
