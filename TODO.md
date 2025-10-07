# ModPro UI Refactor TODO

## Phase 1: Global Styles and Setup
- [x] Create global.css with shared styles, utilities, animations
- [ ] Update all PHP files to link global.css instead of inline styles
- [ ] Harmonize login.php styling with dashboard theme
- [ ] Create consistent color palette (extend Tailwind if needed)

## Phase 2: Layout and Responsiveness
- [ ] Make sidebar collapsible on mobile and desktop
- [ ] Ensure mobile-first responsive design across all views
- [ ] Fix grid layouts for better decluttering (e.g., stat cards, tables)

## Phase 3: Accessibility
- [ ] Add ARIA labels and roles to all interactive elements
- [ ] Ensure logical tab order
- [ ] Add focus management for modals
- [ ] Implement keyboard shortcuts (e.g., Ctrl+S for save, Esc for close)
- [ ] Add screen reader support

## Phase 4: UI Improvements
- [ ] Improve visual hierarchy on stat cards
- [ ] Add empty state messaging for tables/lists
- [ ] Fix animations and transitions
- [ ] Optimize load performance with lazy loading for heavy libraries

## Phase 5: Code Cleanup
- [ ] Remove duplicate CSS classes and incorrect names
- [ ] Deduplicate shared CSS into global styles
- [ ] Add comments explaining structural changes
- [ ] Ensure clean, well-documented code

## Files to Update
- index.php (dashboard)
- login.php
- sales.php
- products.php
- purchases.php
- returns.php
- out_of_stock.php
- sidebar.php
- Any other views

## Testing
- [ ] Test on mobile devices
- [ ] Test accessibility with screen readers
- [ ] Test keyboard navigation
- [ ] Verify RTL typography
