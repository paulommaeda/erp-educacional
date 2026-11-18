<?php

namespace EducacionalERP\Vendor\Sabberworm\CSS\Comment;

use EducacionalERP\Vendor\Sabberworm\CSS\OutputFormat;
use EducacionalERP\Vendor\Sabberworm\CSS\Renderable;
use EducacionalERP\Vendor\Sabberworm\CSS\Position\Position;
use EducacionalERP\Vendor\Sabberworm\CSS\Position\Positionable;
class Comment implements Positionable, Renderable
{
    use Position;
    /**
     * @var string
     *
     * @internal since 8.8.0
     */
    protected $sComment;
    /**
     * @param string $sComment
     * @param int $iLineNo
     */
    public function __construct($sComment = '', $iLineNo = 0)
    {
        $this->sComment = $sComment;
        $this->setPosition($iLineNo);
    }
    /**
     * @return string
     */
    public function getComment()
    {
        return $this->sComment;
    }
    /**
     * @param string $sComment
     *
     * @return void
     */
    public function setComment($sComment)
    {
        $this->sComment = $sComment;
    }
    /**
     * @return string
     *
     * @deprecated in V8.8.0, will be removed in V9.0.0. Use `render` instead.
     */
    public function __toString()
    {
        return $this->render(new OutputFormat());
    }
    /**
     * @param OutputFormat|null $oOutputFormat
     *
     * @return string
     */
    public function render($oOutputFormat)
    {
        return '/*' . $this->sComment . '*/';
    }
}
