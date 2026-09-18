import { Link } from '@inertiajs/react';
import {
    CalendarClock,
    ClipboardList,
    FileText,
    ListChecks,
    Network,
    Radio,
    ScanLine,
    Send,
    ShieldCheck,
    UserCog,
    Users,
} from 'lucide-react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { usePermissions } from '@/hooks/use-permissions';
import type { NavItem } from '@/types';

type NavGroup = {
    label: string;
    items: NavItem[];
    /** Bila true, hanya SuperAdmin/Admin yang melihat grup ini. */
    adminOnly?: boolean;
};

/**
 * Navigasi ORP: dikelompokkan dan disaring per permission (RBAC).
 * Penegakan sebenarnya tetap di API — ini hanya agar menu tidak menyesatkan.
 */
export function NavOrp() {
    const { can, isAdmin } = usePermissions();
    const { isCurrentUrl } = useCurrentUrl();

    const groups: NavGroup[] = [
        {
            label: 'Operasional',
            items: [
                can('orders.view') && {
                    title: 'Worklist',
                    href: '/worklist',
                    icon: ClipboardList,
                },
                can('orders.view') && {
                    title: 'Order',
                    href: '/orders',
                    icon: ListChecks,
                },
                can('scheduling.view') && {
                    title: 'Jadwal',
                    href: '/appointments',
                    icon: CalendarClock,
                },
                can('reports.view') && {
                    title: 'Reporting',
                    href: '/reports',
                    icon: FileText,
                },
                can('orders.view') && {
                    title: 'Study',
                    href: '/studies',
                    icon: ScanLine,
                },
                can('transmission.view') && {
                    title: 'Transmisi',
                    href: '/transmissions',
                    icon: Send,
                },
            ].filter(Boolean) as NavItem[],
        },
        {
            label: 'Master Data',
            items: [
                can('patients.view') && {
                    title: 'Pasien',
                    href: '/patients',
                    icon: Users,
                },
                can('modalities.view') && {
                    title: 'Modalitas',
                    href: '/modalities',
                    icon: Radio,
                },
                can('pacs.view') && {
                    title: 'PACS',
                    href: '/pacs',
                    icon: Network,
                },
            ].filter(Boolean) as NavItem[],
        },
        {
            label: 'Administrasi',
            items: [
                can('audit.view') && {
                    title: 'Audit',
                    href: '/audit',
                    icon: ShieldCheck,
                },
                isAdmin && {
                    title: 'Pengguna',
                    href: '/users',
                    icon: UserCog,
                },
            ].filter(Boolean) as NavItem[],
        },
    ];

    return (
        <>
            {groups
                .filter((group) => group.items.length > 0)
                .map((group) => (
                    <SidebarGroup key={group.label} className="px-2 py-0">
                        <SidebarGroupLabel>{group.label}</SidebarGroupLabel>
                        <SidebarMenu>
                            {group.items.map((item) => (
                                <SidebarMenuItem key={item.title}>
                                    <SidebarMenuButton
                                        asChild
                                        isActive={isCurrentUrl(item.href)}
                                        tooltip={{ children: item.title }}
                                    >
                                        <Link href={item.href} prefetch>
                                            {item.icon && <item.icon />}
                                            <span>{item.title}</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            ))}
                        </SidebarMenu>
                    </SidebarGroup>
                ))}
        </>
    );
}
