import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

/** Class-name helper used by every shadcn component: merges conditionals and resolves Tailwind conflicts. */
export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}
