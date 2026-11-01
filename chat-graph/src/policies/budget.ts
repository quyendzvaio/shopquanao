import type { GraphConfig } from '../config.js';

/** Per-turn limits on tool calls, model calls and wall-clock time. */
export class TurnBudget {
  private tools = 0;
  private models = 0;
  private readonly startedAt: number;

  constructor(private readonly limits: GraphConfig['budget'], private readonly now: () => number = Date.now) {
    this.startedAt = now();
  }

  takeToolCall(): boolean {
    if (this.tools >= this.limits.maxToolCalls) return false;
    this.tools += 1;
    return true;
  }

  takeModelCall(): boolean {
    if (this.models >= this.limits.maxModelCalls) return false;
    this.models += 1;
    return true;
  }

  modelCallsLeft(): number {
    return Math.max(0, this.limits.maxModelCalls - this.models);
  }

  toolCallsLeft(): number {
    return Math.max(0, this.limits.maxToolCalls - this.tools);
  }

  remainingMs(): number {
    return Math.max(0, this.limits.turnTimeoutMs - (this.now() - this.startedAt));
  }

  expired(): boolean {
    return this.remainingMs() <= 0;
  }

  counts(): { tool_calls: number; model_calls: number } {
    return { tool_calls: this.tools, model_calls: this.models };
  }
}
