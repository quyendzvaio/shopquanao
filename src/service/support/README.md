# service/support

`SupportTicketService` holds the rules for order-support tickets filed through the chatbot: the 5 types and 4 statuses, the order must belong to the customer (not needed for `khac`), no second open ticket of the same type on the same order, at most 5 open tickets per user, summary 1-500 characters. `draft` only validates; `create` re-checks and writes, so a ticket exists only after the customer confirmed. It also serves the customer's own list (`listForUser`) and the admin queue (`adminList`, `adminDetail`, `adminUpdate`, `countNew`). SQL lives in `SupportTicketRepository`.
