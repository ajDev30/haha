import re

with open("service.py", "r") as f:
    content = f.read()

replacement = """    def _on_canceled(self, evt: Any) -> None:
        self.canceled_results += 1
        details_obj = getattr(evt, "cancellation_details", None)
        if details_obj:
            reason_enum = getattr(details_obj, "reason", None)
            error_code = str(getattr(details_obj, "error_code", "") or "")
            details = str(getattr(details_obj, "error_details", "") or "")
            reason = str(reason_enum or "")
            if reason_enum == speechsdk.CancellationReason.EndOfStream:
                logger.info("[Azure] session canceled normally (EndOfStream) session=%s", self.session_id)
                self.session_stopped_event.set()
                self._emit_final("canceled")
                return
        else:
            reason = str(getattr(evt, "reason", "") or "")
            error_code = str(getattr(evt, "error_code", "") or "")
            details = str(getattr(evt, "error_details", "") or "")

        message = details or f"Azure recognition canceled: {reason}"
        self.last_error = {
            "message": message,
            "status": 502,
            "provider": "azure",
            "details": {
                "reason": reason,
                "error_code": error_code,
                "error_details": details,
            },
        }
        logger.warning("[Azure] canceled session=%s reason=%s code=%s details=%s", self.session_id, reason, error_code, details)
        self.session_stopped_event.set()
        self._queue_emit({"type": "azure.error", "error": self.last_error})
        self._emit_final("canceled")"""

content = re.sub(
    r"    def _on_canceled\(self, evt: Any\) -> None:[\s\S]*?self\._emit_final\(\"canceled\"\)",
    replacement,
    content
)

with open("service.py", "w") as f:
    f.write(content)
