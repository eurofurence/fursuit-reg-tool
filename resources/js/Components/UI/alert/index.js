import { cva } from "class-variance-authority";

export { default as Alert } from "./Alert.vue";
export { default as AlertDescription } from "./AlertDescription.vue";
export { default as AlertTitle } from "./AlertTitle.vue";

export const alertVariants = cva(
  "relative w-full rounded-lg border px-4 py-3 text-sm grid has-[>svg]:grid-cols-[calc(var(--spacing)*4)_1fr] grid-cols-[0_1fr] has-[>svg]:gap-x-3 gap-y-0.5 items-start [&>svg]:size-4 [&>svg]:translate-y-0.5 [&>svg]:text-current",
  {
    variants: {
      variant: {
        default: "bg-card text-card-foreground",
        destructive:
          "text-destructive bg-card [&>svg]:text-current *:data-[slot=alert-description]:text-destructive/90",
        info: "border-info/40 bg-info/10 text-info *:data-[slot=alert-description]:text-info/90",
        success:
          "border-success/40 bg-success/10 text-success *:data-[slot=alert-description]:text-success/90",
        warning:
          "border-warning bg-warning/10 text-grays-700 [&>svg]:text-warning *:data-[slot=alert-description]:text-grays-700",
      },
    },
    defaultVariants: { variant: "default" },
  },
);
