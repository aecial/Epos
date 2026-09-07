import type { ReactNode } from "react";

const backOfficeUpperDiv = ({ children }: { children: ReactNode }) => {
  return (
    <div className="border-sidebar-border/70 dark:border-sidebar-border relative flex aspect-video items-center justify-center overflow-hidden rounded-xl border">
      {children}
    </div>
  )
}

export default backOfficeUpperDiv