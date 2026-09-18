import type { ReactNode } from 'react';
import { Card, CardContent } from '@/components/ui/card';

export function PageHeader({
    title,
    description,
    actions,
}: {
    title: string;
    description?: string;
    actions?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 className="text-xl font-semibold">{title}</h1>
                {description && (
                    <p className="text-muted-foreground text-sm">
                        {description}
                    </p>
                )}
            </div>
            {actions && <div className="flex gap-2">{actions}</div>}
        </div>
    );
}

/** Kartu "tanpa akses" — dipakai saat user tidak punya permission halaman. */
export function NoAccess({ permission }: { permission: string }) {
    return (
        <Card>
            <CardContent className="text-muted-foreground py-10 text-center text-sm">
                Anda tidak punya izin <code>{permission}</code> untuk membuka
                halaman ini. Hubungi Admin bila ini keliru.
            </CardContent>
        </Card>
    );
}
