import { createNavigation } from "next-intl/navigation";
import { routing } from "./routing";

// Locale-aware Link / router: href="/admin/catalog" becomes /app/<current locale>/admin/catalog/ (basePath and the
// trailing slash are added by Next).
export const { Link, redirect, usePathname, useRouter, getPathname } = createNavigation(routing);
