<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Answer extends Model
{
    use HasFactory;

    protected $fillable = [
        'line_user_id',
        'step',
        'answer',
    ];

    /**
     * このモデルが使用するデータベース接続
     *
     * @var string
     */
    protected $connection = 'sqlite';

    /**
     * モデルに関連付けるテーブル
     *
     * @var string
     */
    protected $table = 'answers';

    /**
     * Reset step
     *
     * @param string
     * @return void
     **/
    public function resetStep(string $line_user_id) : void
    {
        $this->where('line_user_id', $line_user_id)->delete();
    }

    /**
     * Store record for next step
     *
     * @param int
     * @return void
     **/
    public function storeNextStep(string $line_user_id, int $step) : void
    {
        $this->line_user_id = $line_user_id;
        $this->step = $step;
        $this->answer = '';
        $this->save();
    }

    /**
     * Store answer replied by LINE user.
     *
     * @param Answer
     * @param string
     * @return void
     **/
    public function storeAnswer(Answer $answer, string $postback_answer) : void
    {
        $answer->answer = $postback_answer;
        $answer->save();
    }
}
