* local storage (永久保存資料)
  * answers
    * JSON object, key 是題號, value 是 -2 ~ +2 或 null ，配對用的
* session storage
  * rejected
    * JSON array, 已經拒絕的使用者列表，避免重覆對拒絕的使用者做邀請
* property
  * user-status
    * answering: 回答問題中，此時不做配對，一進來狀態是這個
    * talking: 聊天中，此時不能被打擾
    * asking: 詢問中，等雙方都同意就進入 talking
    * pairing: 配對中，此時可被配對
  * answers
    * JSON format, key 是題號, value 是 -2 ~ +2 或 null ，配對用的
* action
  * invite-ask
    * 前端自動配對，對符合條件送出 invite 後自己要變成 asking status 避免被重複邀請
  * reject-ask
    * 前端自動拒絕配對，解除 invite 狀態（可能是已經剛好被其他人邀請了）
  * accept-ask
    * 前端自動接受配對，此時會跳出提示詢問當事人是否接受聊天
  * accept-invite
    * 當事人接受配對，雙方都 accept-invite 就開聊
  * reject-invite
    * 當事人拒絕配對，一方 rejcet 就直接解除回到另外 invite-ask 狀態
* TODO
  * 允許旁觀模式？
  * 結束後允許留存對話記錄?
