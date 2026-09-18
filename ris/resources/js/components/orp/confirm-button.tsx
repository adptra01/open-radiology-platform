import type { ReactNode } from 'react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type ConfirmButtonProps = {
    title: string;
    description?: string;
    confirmLabel?: string;
    /** Tombol pemicu (default: Button variant destructive). */
    trigger?: ReactNode;
    onConfirm: () => void | Promise<void>;
    disabled?: boolean;
    variant?: 'default' | 'destructive' | 'outline' | 'secondary' | 'ghost';
    size?: 'default' | 'sm' | 'lg' | 'icon';
    children?: ReactNode;
};

/** Tombol dengan dialog konfirmasi (aksi destruktif / tak bisa dibatalkan). */
export function ConfirmButton({
    title,
    description,
    confirmLabel = 'Lanjutkan',
    trigger,
    onConfirm,
    disabled,
    variant = 'destructive',
    size = 'sm',
    children,
}: ConfirmButtonProps) {
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);

    return (
        <>
            {trigger ? (
                <span onClick={() => setOpen(true)}>{trigger}</span>
            ) : (
                <Button
                    variant={variant}
                    size={size}
                    disabled={disabled}
                    onClick={() => setOpen(true)}
                >
                    {children}
                </Button>
            )}

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        {description && (
                            <DialogDescription>{description}</DialogDescription>
                        )}
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Batal
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={busy}
                            onClick={async () => {
                                setBusy(true);

                                try {
                                    await onConfirm();
                                    setOpen(false);
                                } finally {
                                    setBusy(false);
                                }
                            }}
                        >
                            {busy ? 'Memproses…' : confirmLabel}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
